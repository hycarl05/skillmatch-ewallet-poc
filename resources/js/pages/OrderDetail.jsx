import { useCallback, useEffect, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import AdminBreakdown, { BuyerPayment, TrainerEarnings } from '../MoneyBreakdown';
import { GATEWAY_LABEL, api, rm, when } from '../lib';
import { RefundForm, RefundList } from '../Refunds';
import { Badge, Button, Card, ErrorNote } from '../ui';

function Fact({ label, value, hint }) {
    return (
        <div>
            <dt className="text-slate-500">{label}</dt>
            <dd className="font-medium text-slate-800">{value}</dd>
            {hint && <dd className="mt-0.5 text-xs text-slate-500">{hint}</dd>}
        </div>
    );
}

const NEXT_STEP = {
    pending_payment: 'Waiting for the gateway to confirm payment (webhook).',
    paid: 'Paid. Waiting for the trainer to deliver the training and mark it completed.',
    completed: 'Trainer marked it completed. Waiting for the buyer to confirm (or auto-confirm).',
    confirmed: 'Training confirmed.',
    failed: 'Payment failed. Any SkillPoints used were returned.',
    refunded: 'Refunded. Any SkillPoints used were returned.',
};

export default function OrderDetail() {
    const { id } = useParams();
    const [params] = useSearchParams();
    const [order, setOrder] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [refunding, setRefunding] = useState(false);

    const load = useCallback(() => api(`/orders/${id}`).then(setOrder).catch((e) => setError(e.message)), [id]);

    useEffect(() => {
        load();
    }, [load]);

    // Real gateways can send the webhook a moment after the buyer comes back.
    useEffect(() => {
        if (order?.status !== 'pending_payment' || !params.get('returned')) return;
        const timer = setInterval(load, 2000);
        return () => clearInterval(timer);
    }, [order?.status]);

    // Every action returns the updated order.
    const post = async (path, body) => {
        setBusy(true);
        setError(null);
        try {
            setOrder(await api(path, { method: 'POST', body }));
            return true;
        } catch (e) {
            setError(e.message);
            return false;
        } finally {
            setBusy(false);
        }
    };
    const act = (action, body) => post(`/orders/${id}/${action}`, body);

    if (!order) return error ? <ErrorNote error={error} /> : <div className="text-slate-500">Loading…</div>;

    const gatewayName = GATEWAY_LABEL[order.gateway] ?? order.gateway;

    // Only the admin sees every share, so only the admin is told who is still unpaid.
    const isAdmin = order.view_as === 'platform';
    const awaitingPayout = isAdmin ? order.splits.filter((s) => s.recipient && s.status === 'released') : [];
    const disputes = (order.payments ?? []).filter((p) => p.dispute_status);
    const nextStep = order.open_refund_request
        ? 'Refund requested. Waiting for SkillMatch to review it.'
        : order.status === 'confirmed' && awaitingPayout.length > 0
            ? `Confirmed. Waiting to pay ${awaitingPayout.map((s) => s.recipient.name).join(' and ')}${
                  order.gateway === 'stripe' ? ': they need to finish Stripe payout setup in their Wallet.' : '.'
              }`
            : NEXT_STEP[order.status];

    return (
        <div className="space-y-4">
            <Link to="/orders" className="text-sm text-indigo-700 hover:underline">
                ← Orders
            </Link>

            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex items-center gap-3">
                        <h1 className="text-xl font-semibold">{order.reference}</h1>
                        <Badge status={order.status} />
                    </div>
                    <div className="text-sm text-slate-500">
                        {order.training.title} · {order.payout_mode} payout via {gatewayName}
                    </div>
                    <div className="mt-1 text-sm text-slate-600">{nextStep}</div>
                </div>
                <div className="flex flex-wrap gap-2">
                    {order.can.pay && order.checkout_url && (
                        <Button onClick={() => (window.location.href = order.checkout_url)}>Pay now</Button>
                    )}
                    {order.can.complete && (
                        <Button disabled={busy} onClick={() => act('complete')}>
                            Mark training completed
                        </Button>
                    )}
                    {order.can.confirm && (
                        <Button disabled={busy} onClick={() => act('confirm')}>
                            Confirm training received
                        </Button>
                    )}
                    {order.can.request_refund && !refunding && (
                        <Button variant="danger" disabled={busy} onClick={() => setRefunding(true)}>
                            Request refund
                        </Button>
                    )}
                    {order.can.refund && !refunding && (
                        <Button variant="danger" disabled={busy} onClick={() => setRefunding(true)}>
                            Refund
                        </Button>
                    )}
                </div>
            </div>

            {order.refund_blocker && ['paid', 'completed', 'confirmed'].includes(order.status) && (
                <p className="text-xs text-slate-500">{order.refund_blocker}</p>
            )}

            {refunding && (order.can.request_refund || order.can.refund) && (
                <RefundForm
                    order={order}
                    max={isAdmin ? order.refundable_sen : order.refund_policy.buyer_max_sen}
                    chooseFee={isAdmin}
                    note={
                        !isAdmin && order.refund_policy.buyer_max_percent < 100
                            ? `The training has been delivered, so you can ask for up to ${order.refund_policy.buyer_max_percent}% of what you paid.`
                            : null
                    }
                    title={isAdmin ? 'Refund the buyer' : 'Ask SkillMatch for a refund'}
                    submitLabel={isAdmin ? 'Refund now' : 'Send request'}
                    busy={busy}
                    onCancel={() => setRefunding(false)}
                    onSubmit={async (body) => {
                        if (await act(isAdmin ? 'refunds' : 'refund-requests', body)) setRefunding(false);
                    }}
                />
            )}

            <ErrorNote error={error} />

            {disputes.map((p) =>
                p.dispute_status === 'open' ? (
                    <div key={p.id} className="rounded-xl bg-rose-50 p-4 text-sm text-rose-900 ring-1 ring-rose-200">
                        <div className="font-medium">Payment disputed{p.dispute_amount_sen ? ` (${rm(p.dispute_amount_sen)})` : ''}</div>
                        {isAdmin ? (
                            <p className="mt-1">
                                The buyer’s card issuer opened a chargeback{p.dispute_reason ? ` (${p.dispute_reason.replaceAll('_', ' ')})` : ''}. Payouts and refunds
                                are paused until it’s decided. Submit evidence in the {gatewayName} dashboard
                                {p.dispute_reference && <span className="font-mono text-xs"> ({p.dispute_reference})</span>}.
                            </p>
                        ) : (
                            <p className="mt-1">Your bank is reviewing this payment. SkillMatch will follow its decision.</p>
                        )}
                    </div>
                ) : (
                    isAdmin && (
                        <p key={p.id} className="text-sm text-slate-600">
                            Chargeback {p.dispute_status}
                            {p.dispute_status === 'lost' ? ': the card issuer returned the money to the buyer. It’s recorded as a refund below.' : ': payouts resumed.'}
                        </p>
                    )
                ),
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                {isAdmin && (
                    <Card title="Where the money went" className="lg:col-span-2">
                        <AdminBreakdown order={order} />
                        {order.splits.some((s) => s.status === 'clawback_required') && (
                            <p className="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">
                                This order was refunded after the gateway had already paid the trainers (instant split). SkillMatch refunded the buyer
                                and the trainers now owe their share back. This is the main risk of instant split payment.
                            </p>
                        )}
                    </Card>
                )}
                {order.view_as === 'buyer' && (
                    <Card title="Your payment" className="lg:col-span-2">
                        <BuyerPayment order={order} />
                    </Card>
                )}
                {order.view_as === 'trainer' && (
                    <Card title="Your earnings" className="lg:col-span-2">
                        <TrainerEarnings order={order} />
                    </Card>
                )}

                <Card title="Order">
                    <dl className="space-y-3 text-sm">
                        <Fact label="Buyer" value={order.buyer.name} />
                        {order.beneficiary && <Fact label="Attending" value={order.beneficiary.name} />}
                        <Fact label="Training" value={order.training.title} />
                        <Fact label="Paid through" value={gatewayName} />
                        <Fact
                            label="Payout"
                            value={order.payout_mode === 'deferred' ? 'Deferred' : 'Instant'}
                            hint={
                                order.payout_mode === 'deferred'
                                    ? `${gatewayName} holds the trainer’s share until the buyer confirms the training, then pays it out.`
                                    : 'The gateway pays each share the moment the payment arrives.'
                            }
                        />
                    </dl>
                </Card>
            </div>

            {order.refunds?.length > 0 && (
                <Card title="Refunds">
                    <RefundList order={order} busy={busy} post={post} />
                </Card>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                <Card title="Timeline">
                    <ol className="space-y-2 text-sm">
                        {Object.entries(order.timestamps)
                            .filter(([, v]) => v)
                            .map(([k, v]) => (
                                <li key={k} className="flex justify-between gap-2">
                                    <span className="text-slate-600">{k.replace('_at', '').replace('_', ' ')}</span>
                                    <span className="text-slate-500">{when(v)}</span>
                                </li>
                            ))}
                    </ol>
                </Card>
                {order.payments && (
                    <Card title={isAdmin ? 'Gateway records' : 'Payment record'} className="lg:col-span-2">
                        <table className="w-full text-sm">
                            <tbody className="divide-y divide-slate-100">
                                {order.payments.map((p) => (
                                    <tr key={p.id}>
                                        <td className="py-2">
                                            Payment · {GATEWAY_LABEL[p.gateway] ?? p.gateway}
                                            {p.gateway_fee_sen != null && <div className="text-xs text-slate-500">fee {rm(p.gateway_fee_sen)}</div>}
                                        </td>
                                        <td className="break-all px-2 py-2 font-mono text-xs text-slate-500">{p.gateway_reference}</td>
                                        <td className="py-2 text-right tabular-nums">{rm(p.amount_sen)}</td>
                                        <td className="py-2 pl-4">
                                            <Badge status={p.status} />
                                            {p.dispute_status && (
                                                <div className="mt-1">
                                                    <Badge status={p.dispute_status} />
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Card>
                )}
            </div>

            {order.ledger && (
                <Card title="Ledger for this order (double entry: debits = credits)">
                    <div className="space-y-4">
                        {order.ledger.length === 0 && <div className="text-sm text-slate-500">Nothing recorded yet.</div>}
                        {order.ledger.map((t) => (
                            <div key={t.id}>
                                <div className="text-sm font-medium">
                                    {t.description} <span className="font-normal text-slate-400">· {t.type}</span>
                                </div>
                                <table className="mt-1 w-full text-sm tabular-nums">
                                    <tbody>
                                        {t.entries.map((e, i) => (
                                            <tr key={i} className="text-slate-600">
                                                <td className="py-0.5">
                                                    <span className="font-mono text-xs text-slate-400">{e.account}</span> {e.account_name}
                                                </td>
                                                <td className="w-32 py-0.5 text-right">{e.debit_sen ? rm(e.debit_sen) : ''}</td>
                                                <td className="w-32 py-0.5 text-right">{e.credit_sen ? rm(e.credit_sen) : ''}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ))}
                    </div>
                </Card>
            )}
        </div>
    );
}
