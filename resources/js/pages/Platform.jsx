import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, rm, when } from '../lib';
import { Badge, Card, ErrorNote } from '../ui';

const TODO = {
    requested: 'Decide',
    failed: 'Gateway refund failed: retry',
    manual_required: 'Send it in the gateway dashboard',
    pending: 'Take the trainer’s share back',
    succeeded: 'Take the trainer’s share back',
};

/** Refunds that need SkillMatch: requests to decide, refunds that didn't go out, money owed back. */
function RefundQueue() {
    const [refunds, setRefunds] = useState(null);

    useEffect(() => {
        api('/platform/refunds').then(setRefunds).catch(() => setRefunds([]));
    }, []);

    if (!refunds) return null;

    return (
        <Card title={`Refunds to handle (${refunds.length})`}>
            {refunds.length === 0 ? (
                <div className="text-sm text-slate-500">Nothing waiting.</div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <tbody className="divide-y divide-slate-100">
                            {refunds.map((r) => (
                                <tr key={r.id}>
                                    <td className="py-2 pr-4">
                                        <Link to={`/orders/${r.order.id}`} className="font-medium text-indigo-700 hover:underline">
                                            {r.order.reference}
                                        </Link>
                                        <div className="text-xs text-slate-500">
                                            {r.order.buyer} · {r.order.training}
                                        </div>
                                    </td>
                                    <td className="py-2 pr-4 text-slate-600">{r.reason}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums">{rm(r.amount_sen)}</td>
                                    <td className="py-2 pr-4">
                                        <Badge status={r.status} />
                                        <div className="mt-0.5 text-xs text-slate-500">{TODO[r.status]}</div>
                                    </td>
                                    <td className="py-2 text-right text-slate-500">{when(r.created_at)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

export default function Platform() {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        api('/platform/ledger').then(setData).catch((e) => setError(e.message));
    }, []);

    if (error) return <ErrorNote error={error} />;
    if (!data) return <div className="text-slate-500">Loading…</div>;

    const balanced = data.total_debit_sen === data.total_credit_sen;

    return (
        <div className="space-y-4">
            <div className={`rounded-xl p-4 text-sm ring-1 ${balanced ? 'bg-emerald-50 text-emerald-900 ring-emerald-200' : 'bg-rose-50 text-rose-900 ring-rose-200'}`}>
                {balanced ? '✓ Books balance' : '✗ Books do NOT balance'}: total debits {rm(data.total_debit_sen)}, total credits {rm(data.total_credit_sen)}.
                <div className="mt-1 text-xs opacity-80">
                    “Money at payment gateway” is money the gateway is holding. SkillMatch’s own bank only ever receives its commission.
                </div>
            </div>

            <RefundQueue />

            <Card title="Trial balance">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm tabular-nums">
                        <thead className="text-left text-slate-500">
                            <tr>
                                <th className="py-2 pr-4 font-medium">Account</th>
                                <th className="py-2 pr-4 font-medium">Type</th>
                                <th className="py-2 pr-4 text-right font-medium">Debits</th>
                                <th className="py-2 pr-4 text-right font-medium">Credits</th>
                                <th className="py-2 text-right font-medium">Balance</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {data.accounts.map((a) => (
                                <tr key={a.code}>
                                    <td className="py-2 pr-4">
                                        {a.name}
                                        <div className="font-mono text-xs text-slate-400">{a.code}</div>
                                    </td>
                                    <td className="py-2 pr-4 text-slate-500">{a.type}</td>
                                    <td className="py-2 pr-4 text-right">{rm(a.debit_sen)}</td>
                                    <td className="py-2 pr-4 text-right">{rm(a.credit_sen)}</td>
                                    <td className={`py-2 text-right font-medium ${a.balance_sen < 0 ? 'text-rose-700' : ''}`}>{rm(a.balance_sen)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>

            <Card title="Recent webhooks">
                <table className="w-full text-sm">
                    <tbody className="divide-y divide-slate-100">
                        {data.webhooks.map((w) => (
                            <tr key={w.id}>
                                <td className="py-2">{w.gateway}</td>
                                <td className="py-2 font-mono text-xs text-slate-500">{w.event_key}</td>
                                <td className="py-2">{w.result}</td>
                                <td className="py-2 text-right text-slate-500">{when(w.created_at)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <p className="mt-2 text-xs text-slate-500">Duplicate webhooks are rejected before they are stored, so each event appears once.</p>
            </Card>
        </div>
    );
}
