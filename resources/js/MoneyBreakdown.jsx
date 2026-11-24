import { GATEWAY_LABEL, ROLE_LABEL, pct, rm } from './lib';
import { Badge } from './ui';

// Trainer shares alternate between two indigo shades so a 3-way split stays readable.
const TRAINER_COLORS = ['bg-indigo-500', 'bg-indigo-300'];
const FEE_COLOR = 'bg-amber-400';
const PLATFORM_COLOR = 'bg-emerald-500';
const REFUND_COLOR = 'bg-slate-300';

// A share after refunds.
const remaining = (s) => s.amount_sen - (s.refunded_sen ?? 0);

const SHARE_STATUS_HELP = {
    pending: 'Waiting for the buyer to pay.',
    held: 'Held until the buyer confirms the training.',
    released: 'Released to you, waiting to be sent to your account.',
    paid_out: 'Paid out to you.',
    reversed: 'Cancelled: the order was refunded.',
    clawback_required: 'The order was refunded after you were paid. This amount needs to be returned.',
};

/**
 * Admin only: every sen the buyer paid, split into trainer shares, the
 * gateway's processing fee and what SkillMatch actually keeps.
 */
export default function AdminBreakdown({ order }) {
    const gateway = GATEWAY_LABEL[order.gateway] ?? order.gateway;
    const trainers = order.splits.filter((s) => s.recipient);
    const platformSplit = order.splits.find((s) => !s.recipient);
    const fee = order.processing_fee_sen; // null = not known (yet)
    const feeKnown = fee !== null;
    const refunded = order.status === 'refunded';
    const refundedTotal = order.refunded_sen ?? 0;
    const notPaidYet = ['pending_payment', 'failed'].includes(order.status);

    // Refunds are shared pro rata, so SkillMatch gives back part of its commission too.
    const commissionAfterDiscount = order.platform_fee_sen - order.discount_sen;
    const commissionRefunded = platformSplit?.refunded_sen ?? 0;
    const feesRecovered = order.refund_fees_recovered_sen ?? 0; // refund fees the buyer or trainer paid
    const keeps = commissionAfterDiscount - commissionRefunded - (fee ?? 0) + feesRecovered;

    const segments = [
        ...trainers.map((s, i) => ({ key: s.id, label: s.recipient.name, amount: remaining(s), color: TRAINER_COLORS[i % 2] })),
        ...(refundedTotal > 0 ? [{ key: 'refund', label: 'Refunded to buyer', amount: refundedTotal, color: REFUND_COLOR }] : []),
        ...(feeKnown && fee > 0 ? [{ key: 'fee', label: `${gateway} fee`, amount: fee, color: FEE_COLOR }] : []),
        { key: 'platform', label: 'SkillMatch keeps', amount: Math.max(keeps, 0), color: PLATFORM_COLOR },
    ];

    return (
        <div className="space-y-5">
            {/* The three headline figures */}
            <div className="grid grid-cols-3 gap-3">
                <Figure label={notPaidYet ? 'Buyer will pay' : 'Buyer paid'} value={rm(order.amount_sen)} />
                <Figure
                    label={`${gateway} processing fee`}
                    value={feeKnown ? rm(fee) : '—'}
                    hint={feeKnown ? `${pct(fee, order.amount_sen)} of the payment` : null}
                />
                <Figure
                    label="SkillMatch keeps"
                    value={`${keeps < 0 ? '−' : ''}${rm(Math.abs(keeps))}`}
                    hint={refundedTotal > 0 ? 'after refunds' : `${pct(keeps, order.gross_sen)} of the price`}
                    tone={keeps < 0 ? 'text-rose-700' : 'text-emerald-700'}
                />
            </div>

            {!refunded && (
                <div>
                    <div className="flex h-3 w-full overflow-hidden rounded-full bg-slate-100" role="img" aria-label="Split of the buyer's payment">
                        {segments.map((s) =>
                            s.amount > 0 ? (
                                <div
                                    key={s.key}
                                    className={s.color}
                                    style={{ width: `${(s.amount / order.amount_sen) * 100}%` }}
                                    title={`${s.label}: ${rm(s.amount)}`}
                                />
                            ) : null,
                        )}
                    </div>
                    {notPaidYet && <p className="mt-1 text-xs text-slate-500">Planned split. {gateway}’s fee is only known after payment.</p>}
                </div>
            )}

            {/* Who receives what */}
            <table className="w-full text-sm">
                <tbody className="divide-y divide-slate-100">
                    {trainers.map((s, i) => (
                        <tr key={s.id}>
                            <td className="py-2.5">
                                <Swatch color={TRAINER_COLORS[i % 2]} />
                                <span className="font-medium">{s.recipient.name}</span>
                                <div className="ml-5 text-xs text-slate-500">
                                    {ROLE_LABEL[s.recipient.role] ?? s.role} · {pct(s.amount_sen, order.gross_sen)} of the price
                                    {s.refunded_sen > 0 && <span> · {rm(s.refunded_sen)} refunded</span>}
                                    {s.payout_reference && <span className="break-all font-mono"> · {s.payout_reference}</span>}
                                </div>
                            </td>
                            <td className="py-2.5 text-right tabular-nums">{rm(remaining(s))}</td>
                            <td className="w-28 py-2.5 pl-4 text-right">
                                <Badge status={s.status} />
                            </td>
                        </tr>
                    ))}
                    {refundedTotal > 0 && (
                        <tr>
                            <td className="py-2.5">
                                <Swatch color={REFUND_COLOR} />
                                <span className="font-medium">Refunded to buyer</span>
                                <div className="ml-5 text-xs text-slate-500">Taken from every share in proportion to its size</div>
                            </td>
                            <td className="py-2.5 text-right tabular-nums">{rm(refundedTotal)}</td>
                            <td className="w-28 py-2.5 pl-4" />
                        </tr>
                    )}
                    <tr>
                        <td className="py-2.5">
                            <Swatch color={FEE_COLOR} />
                            <span className="font-medium">{gateway} processing fee</span>
                            <div className="ml-5 text-xs text-slate-500">
                                {feeKnown
                                    ? `${pct(fee, order.amount_sen)} of the payment, deducted by ${gateway}`
                                    : notPaidYet
                                      ? 'Charged by the gateway when the payment goes through'
                                      : order.gateway === 'stripe'
                                        ? 'Waiting for Stripe to report it. Refresh in a moment.'
                                        : `${gateway} doesn’t report its fee to SkillMatch`}
                            </div>
                        </td>
                        <td className="py-2.5 text-right tabular-nums">{feeKnown ? rm(fee) : '—'}</td>
                        <td className="w-28 py-2.5 pl-4" />
                    </tr>
                    <tr>
                        <td className="py-2.5">
                            <Swatch color={PLATFORM_COLOR} />
                            <span className="font-medium">SkillMatch keeps</span>
                            <div className="ml-5 text-xs text-slate-500">
                                {refunded
                                    ? 'Commission reversed by the refund'
                                    : `${pct(keeps, order.gross_sen)} of the price, after discount and fees`}
                            </div>
                        </td>
                        <td className={`py-2.5 text-right font-medium tabular-nums ${keeps < 0 ? 'text-rose-700' : 'text-emerald-700'}`}>
                            {keeps < 0 ? '−' : ''}
                            {rm(Math.abs(keeps))}
                        </td>
                        <td className="w-28 py-2.5 pl-4 text-right">{platformSplit && <Badge status={platformSplit.status} />}</td>
                    </tr>
                </tbody>
            </table>

            {/* How SkillMatch's cut is worked out */}
            <div className="rounded-lg bg-slate-50 p-4 ring-1 ring-slate-200">
                <div className="mb-2 text-sm font-medium text-slate-800">How SkillMatch’s commission works</div>
                <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm tabular-nums">
                    <dt className="text-slate-600">
                        Commission ({order.platform_fee_percent}% of {rm(order.gross_sen)})
                    </dt>
                    <dd className="text-right">{rm(order.platform_fee_sen)}</dd>
                    {order.discount_sen > 0 && (
                        <>
                            <dt className="text-slate-600">− SkillPoints discount (SkillMatch pays it)</dt>
                            <dd className="text-right">− {rm(order.discount_sen)}</dd>
                        </>
                    )}
                    <dt className="text-slate-600">− {gateway} processing fee</dt>
                    <dd className="text-right">{feeKnown ? `− ${rm(fee)}` : '—'}</dd>
                    {commissionRefunded > 0 && (
                        <>
                            <dt className="text-slate-600">− Commission given back in refunds</dt>
                            <dd className="text-right">− {rm(commissionRefunded)}</dd>
                        </>
                    )}
                    {feesRecovered > 0 && (
                        <>
                            <dt className="text-slate-600">+ Processing fee recovered on refunds (buyer / trainer paid)</dt>
                            <dd className="text-right">+ {rm(feesRecovered)}</dd>
                        </>
                    )}
                    <dt className="border-t border-slate-200 pt-1 font-medium">= SkillMatch keeps</dt>
                    <dd className={`border-t border-slate-200 pt-1 text-right font-medium ${keeps < 0 ? 'text-rose-700' : ''}`}>
                        {keeps < 0 ? '−' : ''}
                        {rm(Math.abs(keeps))}
                    </dd>
                </dl>
                <p className="mt-3 text-xs text-slate-500">
                    {refunded && feesRecovered > 0
                        ? `${gateway} keeps its processing fee on a refund. The buyer or trainer paid ${rm(feesRecovered)} of it, so SkillMatch only bears the rest.`
                        : refunded
                        ? `The buyer got the full amount back, but ${gateway} keeps its processing fee, so the refund costs SkillMatch that fee.`
                        : refundedTotal > 0
                          ? `Part of the payment was refunded. Each share gave back the same proportion; the discount and the ${gateway} fee still come out of SkillMatch’s commission.`
                          : `Trainers always receive their full share. The discount and the ${gateway} fee both come out of SkillMatch’s commission.`}
                </p>
            </div>
        </div>
    );
}

/** Buyer: only what they pay. */
export function BuyerPayment({ order }) {
    const notPaidYet = ['pending_payment', 'failed'].includes(order.status);
    const refunded = order.status === 'refunded';

    return (
        <div className="space-y-4">
            <div>
                <div className="text-sm text-slate-500">{notPaidYet ? 'You will pay' : 'You paid'}</div>
                <div className="text-2xl font-semibold tabular-nums">{rm(order.amount_sen)}</div>
                {refunded && <div className="mt-1 text-sm text-slate-600">Refunded in full.</div>}
                {!refunded && order.refunded_sen > 0 && (
                    <div className="mt-1 text-sm text-slate-600">{rm(order.refunded_sen)} refunded so far.</div>
                )}
            </div>
            <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 border-t border-slate-100 pt-3 text-sm tabular-nums">
                <dt className="text-slate-500">Training price</dt>
                <dd className="text-right">{rm(order.gross_sen)}</dd>
                {order.discount_sen > 0 && (
                    <>
                        <dt className="text-slate-500">SkillPoints ({order.skillpoints_used.toLocaleString()})</dt>
                        <dd className="text-right">− {rm(order.discount_sen)}</dd>
                    </>
                )}
                <dt className="font-medium">Total</dt>
                <dd className="text-right font-medium">{rm(order.amount_sen)}</dd>
            </dl>
        </div>
    );
}

/** Trainer: only what they receive. */
export function TrainerEarnings({ order }) {
    const gateway = GATEWAY_LABEL[order.gateway] ?? order.gateway;
    const splits = order.my_splits;
    const status = splits[0]?.status ?? 'pending';

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <div className="text-sm text-slate-500">{status === 'paid_out' ? 'You received' : 'You will receive'}</div>
                    <div className="text-2xl font-semibold tabular-nums">{rm(order.my_amount_sen)}</div>
                </div>
                <Badge status={status} />
            </div>
            <p className="text-sm text-slate-600">{SHARE_STATUS_HELP[status]}</p>
            {splits.some((s) => s.payout_offset_sen > 0) && (
                <p className="text-sm text-slate-600">
                    {rm(splits.reduce((t, s) => t + s.payout_offset_sen, 0))} was kept from this payout for refund processing fees you owed.
                </p>
            )}
            {order.my_refunded_sen > 0 && status !== 'reversed' && (
                <p className="text-sm text-slate-600">{rm(order.my_refunded_sen)} of your share went back to the buyer in a partial refund.</p>
            )}
            {status === 'released' && order.gateway === 'stripe' && (
                <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
                    If you haven’t finished setting up {gateway} payouts, do it in your Wallet. Payment is sent once it’s done.
                </p>
            )}
            {splits.some((s) => s.payout_reference) && (
                <div className="text-xs text-slate-500">
                    Payout reference{' '}
                    {splits
                        .filter((s) => s.payout_reference)
                        .map((s) => (
                            <span key={s.id} className="break-all font-mono">
                                {s.payout_reference}
                            </span>
                        ))}
                </div>
            )}
        </div>
    );
}

function Figure({ label, value, hint, tone = '' }) {
    return (
        <div className="rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
            <div className="text-xs text-slate-500">{label}</div>
            <div className={`mt-0.5 text-lg font-semibold tabular-nums ${tone}`}>{value}</div>
            {hint && <div className="text-xs text-slate-500">{hint}</div>}
        </div>
    );
}

function Swatch({ color }) {
    return <span className={`mr-2 inline-block h-3 w-3 rounded-sm align-[-1px] ${color}`} aria-hidden="true" />;
}
