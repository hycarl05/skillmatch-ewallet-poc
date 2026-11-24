<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Payments\GatewayManager;
use App\Payments\InvalidWebhookSignature;
use App\Payments\WebhookResult;
use App\Services\OrderService;
use App\Services\RefundService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The only place a payment is marked as paid. The browser coming back to
 * the "success" page proves nothing; the gateway's signed webhook does.
 */
class WebhookController extends Controller
{
    public function __invoke(string $gateway, Request $request, GatewayManager $gateways, OrderService $orders, RefundService $refunds): JsonResponse
    {
        try {
            $result = $gateways->gateway($gateway)->parseWebhook($request);
        } catch (InvalidArgumentException) {
            return response()->json(['result' => 'unknown_gateway'], 404);
        } catch (InvalidWebhookSignature $e) {
            Log::warning("Rejected {$gateway} webhook", ['reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return response()->json(['result' => 'invalid_signature'], 401);
        }

        // Gateways retry webhooks, so the same event can arrive more than once.
        // The unique (gateway, event_key) index makes sure it's applied once.
        // Recording the event and applying it happen in one DB transaction: if
        // applying fails, the event isn't stored and the gateway's retry works.
        try {
            $outcome = DB::transaction(function () use ($gateway, $result, $orders, $refunds) {
                $event = WebhookEvent::create([
                    'gateway' => $gateway,
                    'event_key' => $result->eventKey,
                    'payload' => $result->payload,
                ]);

                $outcome = $result->kind === WebhookResult::KIND_PAYMENT
                    ? $orders->applyWebhook($gateway, $result)
                    : $refunds->applyWebhook($gateway, $result);
                $event->update(['result' => $outcome]);

                return $outcome;
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['result' => 'duplicate']);
        }

        // Best effort, outside the transaction: if the fee isn't known yet,
        // viewing the order asks again.
        if ($result->kind === WebhookResult::KIND_PAYMENT && $outcome === 'paid' && $payment = Payment::where('gateway', $gateway)->where('gateway_reference', $result->reference)->first()) {
            $orders->recordProcessingFee($payment);
        }

        // Always 200 for a verified webhook, so the gateway stops retrying.
        return response()->json(['result' => $outcome]);
    }
}
