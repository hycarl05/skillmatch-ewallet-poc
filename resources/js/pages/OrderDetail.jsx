import { useCallback, useEffect, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { api, rm, when } from '../lib';
import { Badge, Button, Card, ErrorNote } from '../ui';

const NEXT_STEP = {
    pending_payment: 'Waiting for the gateway to confirm payment (webhook).',
    paid: 'Paid. Waiting for the trainer to deliver the training and mark it completed.',
    completed: 'Trainer marked it completed. Waiting for the buyer to confirm (or auto-confirm).',
    confirmed: 'Done. Everyone has been paid.',
    failed: 'Payment failed. Any SkillPoints used were returned.',
    refunded: 'Refunded. Any SkillPoints used were returned.',
};

export default function OrderDetail() {
    const { id } = useParams();
    const [params] = useSearchParams();
    const [order, setOrder] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

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

    const act = async (action, body) => {
        setBusy(true);
        setError(null);
        try {
            setOrder(await api(`/orders/${id}/${action}`, { method: 'POST', body }));
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    };

    if (!order) return error ? <ErrorNote error={error} /> : <div className="text-slate-500">Loading…</div>;

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
                        {order.training.title} · {order.payout_mode} payout via {order.gateway}
                    </div>
                    <div className="mt-1 text-sm text-slate-600">{NEXT_STEP[order.status]}</div>
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
                    {order.can.refund && (
                        <Button variant="danger" disabled={busy} onClick={() => act('refund', { reason: 'Cancelled in PoC demo' })}>
                            Cancel &amp; refund
                        </Button>
                    )}
                </div>
            </div>

            <ErrorNote error={error} />

            <div className="grid gap-4 lg:grid-cols-3">
                <Card title="Payment">
                    <dl className="grid grid-cols-2 gap-y-1 text-sm tabular-nums">
                        <dt className="text-slate-500">Buyer</dt>
                        <dd className="text-right">{order.buyer.name}</dd>
                        {order.beneficiary && (
                            <>
                                <dt className="text-slate-500">Attending</dt>
                                <dd className="text-right">{order.beneficiary.name}</dd>
                            </>
                        )}
                        <dt className="text-slate-500">Price</dt>
                        <dd className="text-right">{rm(order.gross_sen)}</dd>
                        <dt className="text-slate-500">SkillPoints ({order.skillpoints_used})</dt>
                        <dd className="text-right">− {rm(order.discount_sen)}</dd>
                        <dt className="font-medium">Paid to gateway</dt>
                        <dd className="text-right font-medium">{rm(order.amount_sen)}</dd>
                    </dl>
                </Card>

                <Card title="Who gets what" className="lg:col-span-2">
                    <table className="w-full text-sm">
                        <tbody className="divide-y divide-slate-100">
                            {order.splits.map((s) => (
                                <tr key={s.id}>
                                    <td className="py-2">
                                        {s.recipient ? s.recipient.name : 'SkillMatch (commission)'}
                                        <div className="text-xs text-slate-500">{s.role}</div>
                                    </td>
                                    <td className="py-2 text-right tabular-nums">{rm(s.amount_sen)}</td>
                                    <td className="py-2 pl-4">
                                        <Badge status={s.status} />
                                    </td>
                                    <td className="py-2 pl-4 text-xs text-slate-500">{s.payout_reference}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {order.splits.some((s) => s.status === 'clawback_required') && (
                        <p className="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">
                            This order was refunded after the gateway had already paid the trainers (instant split). SkillMatch refunded the buyer and
                            the trainers now owe their share back. This is the main risk of instant split payment.
                        </p>
                    )}
                </Card>
            </div>

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
                <Card title="Gateway records" className="lg:col-span-2">
                    <table className="w-full text-sm">
                        <tbody className="divide-y divide-slate-100">
                            {order.payments.map((p) => (
                                <tr key={p.id}>
                                    <td className="py-2">Payment · {p.gateway}</td>
                                    <td className="py-2 font-mono text-xs text-slate-500">{p.gateway_reference}</td>
                                    <td className="py-2 text-right tabular-nums">{rm(p.amount_sen)}</td>
                                    <td className="py-2 pl-4">
                                        <Badge status={p.status} />
                                    </td>
                                </tr>
                            ))}
                            {order.refunds.map((r) => (
                                <tr key={`r${r.id}`}>
                                    <td className="py-2">Refund · {r.reason}</td>
                                    <td className="py-2 font-mono text-xs text-slate-500">{r.gateway_reference}</td>
                                    <td className="py-2 text-right tabular-nums">{rm(r.amount_sen)}</td>
                                    <td className="py-2 pl-4">
                                        <Badge status={r.status === 'succeeded' ? 'refunded' : r.status} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {order.refunds.some((r) => r.status === 'manual_required') && (
                        <p className="mt-2 text-xs text-amber-700">This gateway cannot refund automatically: finish the refund in the gateway dashboard.</p>
                    )}
                </Card>
            </div>

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
        </div>
    );
}
