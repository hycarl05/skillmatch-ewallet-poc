<?php

namespace App\Http\Controllers\Api;

use App\Enums\PayoutMode;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Order;
use App\Models\Training;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Order::query()->latest('id');
        if ($user->role !== UserRole::Platform) {
            $query->where(fn ($q) => $q
                ->where('buyer_id', $user->id)
                ->orWhere('beneficiary_id', $user->id)
                ->orWhereHas('splits', fn ($s) => $s->where('recipient_id', $user->id)));
        }

        return response()->json($query->get()->map(fn ($o) => Present::order($o, $user)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'training_id' => ['required', 'exists:trainings,id'],
            'beneficiary_id' => ['nullable', 'exists:users,id'],
            'skillpoints' => ['nullable', 'integer', 'min:0'],
            'gateway' => ['nullable', 'string'],
            'payout_mode' => ['nullable', Rule::enum(PayoutMode::class)],
            // hrdc = book an HRDC-claimable training without paying here; HRDC pays the provider
            'funding' => ['nullable', 'in:platform,hrdc'],
        ]);

        $result = $this->orders->create(
            $request->user(),
            Training::findOrFail($data['training_id']),
            isset($data['beneficiary_id']) ? User::find($data['beneficiary_id']) : null,
            (int) ($data['skillpoints'] ?? 0),
            $data['gateway'] ?? config('skillmatch.default_gateway'),
            isset($data['payout_mode']) ? PayoutMode::from($data['payout_mode']) : null,
            $data['funding'] ?? 'platform',
        );

        return response()->json([
            'order' => Present::order($result['order'], $request->user()),
            'checkout_url' => $result['checkout_url'],
        ], 201);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeView($request->user(), $order);

        $order->payments()->whereNull('gateway_fee_sen')->whereIn('status', ['paid', 'refunded'])->get()
            ->each(fn ($payment) => $this->orders->recordProcessingFee($payment));

        return response()->json(Present::order($order, $request->user(), detail: true));
    }

    public function complete(Request $request, Order $order): JsonResponse
    {
        return response()->json(Present::order($this->orders->complete($order, $request->user()), $request->user(), true));
    }

    public function confirm(Request $request, Order $order): JsonResponse
    {
        return response()->json(Present::order($this->orders->confirm($order, $request->user()), $request->user(), true));
    }


    private function authorizeView(User $user, Order $order): void
    {
        abort_unless($user->role === UserRole::Platform || $order->involves($user), 403);
    }
}
