import { useState } from 'react';
import { GATEWAY_LABEL, rm, when } from './lib';
import { Badge, Button } from './ui';

const toSen = (ringgit) => Math.round(parseFloat(ringgit) * 100);
const toRinggit = (sen) => (sen / 100).toFixed(2);

const SOURCE_LABEL = {
    buyer: 'Requested by the buyer',
    platform: 'Refunded by SkillMatch',
    chargeback: 'Chargeback (card issuer)',
    gateway_dashboard: (gateway) => `Refunded in the ${gateway} dashboard`,
};

const sourceLabel = (source, gatewayKey) => {
    const label = SOURCE_LABEL[source] ?? source;
    return typeof label === 'function' ? label(GATEWAY_LABEL[gatewayKey] ?? gatewayKey) : label;
};

// What the buyer is told. They don't need the gateway's internal failure details.
const BUYER_STATUS = {
    requested: 'Waiting for SkillMatch to review your request.',
    rejected: 'SkillMatch declined this request.',
    processing: 'Being sent back to you.',
    pending: 'On its way back to you. Banks can take a few working days.',
    succeeded: 'Refunded to you.',
    failed: 'SkillMatch is sending this refund to you.',
    manual_required: 'SkillMatch is sending this refund to you.',
};

const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';

// Who pays the refund's share of the gateway fee, chosen by SkillMatch.
export const FEE_CHOICES = [
    { value: 'buyer', label: 'Buyer changed their mind', hint: 'fee deducted from the refund' },
    { value: 'trainer', label: 'Trainer cancelled or didn’t deliver', hint: 'trainer owes the fee, kept from their next payout' },
    { value: 'skillmatch', label: 'Goodwill', hint: 'SkillMatch pays the fee' },
];

const FEE_PAID_BY_LABEL = { buyer: 'the buyer', trainer: 'the trainer', skillmatch: 'SkillMatch' };

/** The refund's share of the fee: fee x refunded / paid, as the server works it out. */
const feeShare = (gross, order) => {
    const fee = order.refund_policy?.payment_fee_sen ?? order.processing_fee_sen ?? 0;
    return order.amount_sen > 0 ? Math.round((fee * gross) / order.amount_sen) : 0;
};

function FeeChoice({ value, onChange }) {
    return (
        <fieldset className="text-sm">
            <legend className="text-slate-600">Reason for the refund (decides who pays the processing fee)</legend>
            <div className="mt-1 space-y-1">
                {FEE_CHOICES.map((c) => (
                    <label key={c.value} className="flex items-start gap-2">
                        <input type="radio" name="fee_paid_by" value={c.value} checked={value === c.value} onChange={() => onChange(c.value)} className="mt-1" />
                        <span>
                            {c.label} <span className="text-xs text-slate-500">· {c.hint}</span>
                        </span>
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

/** What the buyer will actually receive. */
function Payout({ gross, feePaidBy, order, forBuyer }) {
    if (!(gross >= 1)) return null;
    const fee = feeShare(gross, order);
    const deducted = feePaidBy === 'buyer' && fee > 0;
    const feeKnown = (order.refund_policy?.payment_fee_sen ?? order.processing_fee_sen) != null;

    return (
        <p className="rounded-lg bg-slate-50 p-2 text-sm text-slate-700 ring-1 ring-slate-200">
            {deducted ? (
                <>
                    {rm(gross)} − {rm(fee)} non-refundable processing fee = <strong>{rm(gross - fee)}</strong> {forBuyer ? 'back to you' : 'to the buyer'}
                </>
            ) : (
                <>
                    <strong>{rm(gross)}</strong> {forBuyer ? 'back to you' : 'to the buyer'}
                    {!forBuyer && fee > 0 && <span className="text-slate-500"> · {rm(fee)} fee paid by {FEE_PAID_BY_LABEL[feePaidBy]}</span>}
                </>
            )}
            {!feeKnown && <span className="block text-xs text-slate-500">The gateway hasn’t reported its fee yet; it’s worked out when the refund is made.</span>}
        </p>
    );
}

/**
 * Amount + reason. The buyer's request shows the stage limit and that the
 * fee is deducted; SkillMatch's direct refund also picks who pays the fee.
 */
export function RefundForm({ order, max, title, submitLabel, busy, onSubmit, onCancel, chooseFee = false, note }) {
    const [amount, setAmount] = useState(toRinggit(max));
    const [reason, setReason] = useState('');
    const [feePaidBy, setFeePaidBy] = useState(chooseFee ? 'skillmatch' : 'buyer');
    const sen = toSen(amount);
    const valid = sen >= 1 && sen <= max && reason.trim() !== '';

    return (
        <form
            className="space-y-3 rounded-xl border border-slate-200 bg-white p-4"
            onSubmit={(e) => {
                e.preventDefault();
                if (valid) onSubmit({ amount_sen: sen, reason: reason.trim(), ...(chooseFee ? { fee_paid_by: feePaidBy } : {}) });
            }}
        >
            <div className="font-medium text-slate-800">{title}</div>
            <div className="grid gap-3 sm:grid-cols-[12rem_1fr]">
                <label className="block text-sm">
                    <span className="text-slate-600">Amount (RM)</span>
                    <input type="number" min="0.01" step="0.01" max={toRinggit(max)} value={amount} onChange={(e) => setAmount(e.target.value)} className={`mt-1 ${inputClass}`} />
                    <span className="mt-1 block text-xs text-slate-500">Up to {rm(max)}</span>
                </label>
                <label className="block text-sm">
                    <span className="text-slate-600">Reason</span>
                    <input type="text" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} className={`mt-1 ${inputClass}`} placeholder="e.g. The training was cancelled" />
                </label>
            </div>
            {note && <p className="text-xs text-slate-500">{note}</p>}
            {chooseFee && <FeeChoice value={feePaidBy} onChange={setFeePaidBy} />}
            {/* A buyer's request: the fee is deducted unless SkillMatch finds the trainer at fault. */}
            <Payout gross={sen} feePaidBy={feePaidBy} order={order} forBuyer={!chooseFee} />
            {!chooseFee && (
                <p className="text-xs text-slate-500">
                    If SkillMatch finds the trainer cancelled or didn’t deliver, you get the full amount back with no fee.
                </p>
            )}
            <div className="flex gap-2">
                <Button type="submit" disabled={busy || !valid}>
                    {submitLabel}
                </Button>
                <Button type="button" variant="secondary" onClick={onCancel}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

/** Every refund on the order. SkillMatch also sees how it was split and can act on it. */
export function RefundList({ order, busy, post }) {
    if (!order.refunds?.length) return null;
    const isAdmin = order.view_as === 'platform';

    return (
        <ul className="divide-y divide-slate-100">
            {order.refunds.map((r) => (
                <li key={r.id} className="py-3 first:pt-0 last:pb-0">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="font-medium tabular-nums">{rm(r.amount_sen)}</span>
                                <Badge status={r.status} />
                            </div>
                            <div className="text-xs text-slate-500">
                                {sourceLabel(r.source, order.gateway)}
                                {isAdmin && r.requested_by ? ` · ${r.requested_by}` : ''} · {when(r.created_at)}
                            </div>
                        </div>
                        {isAdmin && r.gateway_reference && <div className="break-all font-mono text-xs text-slate-500">{r.gateway_reference}</div>}
                    </div>

                    {r.fee_sen > 0 && r.status !== 'requested' && (
                        <p className="mt-1 text-xs text-slate-600">
                            {r.fee_paid_by === 'buyer'
                                ? `${rm(r.gross_sen)} refunded − ${rm(r.fee_sen)} non-refundable processing fee`
                                : `${rm(r.fee_sen)} processing fee paid by ${FEE_PAID_BY_LABEL[r.fee_paid_by]}`}
                        </p>
                    )}
                    <p className="mt-1 text-sm text-slate-700">{r.reason}</p>
                    {r.review_note && (
                        <p className="mt-1 text-sm text-slate-600">
                            <span className="text-slate-500">SkillMatch:</span> {r.review_note}
                        </p>
                    )}
                    {!isAdmin && <p className="mt-1 text-xs text-slate-500">{BUYER_STATUS[r.status]}</p>}

                    {isAdmin && <AdminRefundDetail refund={r} order={order} busy={busy} post={post} />}
                </li>
            ))}
        </ul>
    );
}

function AdminRefundDetail({ refund: r, order, busy, post }) {
    const [mode, setMode] = useState(null); // approve | reject | mark_sent
    const [amount, setAmount] = useState(toRinggit(r.gross_sen));
    const [text, setText] = useState('');
    const [feePaidBy, setFeePaidBy] = useState('buyer');

    const submit = (e) => {
        e.preventDefault();
        if (mode === 'approve') post(`/refunds/${r.id}/approve`, { amount_sen: toSen(amount), note: text || null, fee_paid_by: feePaidBy });
        if (mode === 'reject') post(`/refunds/${r.id}/reject`, { note: text });
        if (mode === 'mark_sent') post(`/refunds/${r.id}/mark-sent`, { reference: text || null });
        setMode(null);
    };

    return (
        <div className="mt-2 space-y-2">
            {r.failure_reason && <p className="rounded-lg bg-rose-50 p-2 text-xs text-rose-800 ring-1 ring-rose-200">{r.failure_reason}</p>}
            {r.status === 'manual_required' && (
                <p className="text-xs text-amber-700">This gateway can’t refund by API. Send the money from the gateway dashboard, then mark it as sent.</p>
            )}

            {r.allocation.length > 0 && (
                <table className="w-full text-xs text-slate-600">
                    <tbody>
                        {r.allocation.map((a) => (
                            <tr key={a.split_id}>
                                <td className="py-0.5">{a.recipient ?? 'SkillMatch commission'}</td>
                                <td className="py-0.5 text-right tabular-nums">− {rm(a.amount_sen)}</td>
                                <td className="w-1/2 py-0.5 pl-3">
                                    {a.fee_debt_sen > 0 && <span className="mr-2 text-amber-700">owes {rm(a.fee_debt_sen)} fee</span>}
                                    {a.clawback &&
                                        (a.reversal_reference ? (
                                            <span className="text-emerald-700">
                                                taken back · <span className="break-all font-mono">{a.reversal_reference}</span>
                                            </span>
                                        ) : (
                                            <span className="text-rose-700">owed back{a.clawback_error ? `: ${a.clawback_error}` : ''}</span>
                                        ))}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {r.attempts > 1 && <div className="text-xs text-slate-500">Sent to the gateway {r.attempts} times</div>}

            {mode ? (
                <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
                    {mode === 'approve' && (
                        <div className="w-full space-y-2">
                            <FeeChoice value={feePaidBy} onChange={setFeePaidBy} />
                            <Payout gross={toSen(amount)} feePaidBy={feePaidBy} order={order} forBuyer={false} />
                        </div>
                    )}
                    {mode === 'approve' && (
                        <label className="text-xs text-slate-600">
                            Amount (RM)
                            <input type="number" min="0.01" step="0.01" max={toRinggit(r.gross_sen)} value={amount} onChange={(e) => setAmount(e.target.value)} className={`mt-1 block w-32 ${inputClass}`} />
                        </label>
                    )}
                    <label className="min-w-48 flex-1 text-xs text-slate-600">
                        {mode === 'approve' ? 'Note to the buyer (optional)' : mode === 'reject' ? 'Why (the buyer sees this)' : 'Gateway or bank reference (optional)'}
                        <input type="text" maxLength={255} value={text} onChange={(e) => setText(e.target.value)} className={`mt-1 ${inputClass}`} />
                    </label>
                    <Button type="submit" disabled={busy || (mode === 'reject' && !text.trim())}>
                        {mode === 'approve' ? 'Approve & refund' : mode === 'reject' ? 'Reject' : 'Mark as sent'}
                    </Button>
                    <Button type="button" variant="secondary" onClick={() => setMode(null)}>
                        Cancel
                    </Button>
                </form>
            ) : (
                <div className="flex flex-wrap gap-2">
                    {r.actions.approve && (
                        <Button disabled={busy} onClick={() => setMode('approve')}>
                            Approve
                        </Button>
                    )}
                    {r.actions.reject && (
                        <Button variant="danger" disabled={busy} onClick={() => setMode('reject')}>
                            Reject
                        </Button>
                    )}
                    {r.actions.retry && (
                        <Button disabled={busy} onClick={() => post(`/refunds/${r.id}/retry`)}>
                            {r.status === 'failed' ? 'Retry refund' : 'Retry taking it back'}
                        </Button>
                    )}
                    {r.actions.mark_sent && (
                        <Button variant="secondary" disabled={busy} onClick={() => setMode('mark_sent')}>
                            Mark as sent
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}
