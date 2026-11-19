import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useApp } from '../context';
import { api, rm, when } from '../lib';
import { Card, Stat } from '../ui';

export default function Wallet() {
    const { me } = useApp();
    const [w, setW] = useState(null);

    useEffect(() => {
        api('/wallet').then(setW);
    }, []);

    if (!w) return <div className="text-slate-500">Loading…</div>;

    const sells = ['provider', 'freelancer'].includes(me.role);

    return (
        <div className="space-y-4">
            <div>
                <h1 className="text-xl font-semibold">Wallet</h1>
                <p className="text-sm text-slate-500">
                    A view of your money as recorded by SkillMatch. The money itself is held by the payment gateway or is already in your bank, never in SkillMatch.
                </p>
            </div>

            {sells && (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Held" value={rm(w.earnings.held_sen)} hint="Paid by buyers, waiting for training confirmation" />
                    <Stat label="Releasing" value={rm(w.earnings.releasing_sen)} hint="Released, payout to your bank in progress" />
                    <Stat label="Paid out" value={rm(w.earnings.paid_out_sen)} hint="Already sent to your bank" />
                    <Stat label="Owed back" value={rm(w.earnings.owed_back_sen)} hint="Refunds after you were paid" />
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-3">
                <Stat label="SkillPoints" value={w.skillpoints.balance.toLocaleString()} hint="Discount only. Can’t be withdrawn or transferred." />
                <Stat label="Spent on trainings" value={rm(w.spending.paid_sen)} />
                <Stat label="Refunded to you" value={rm(w.spending.refunded_sen)} />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {sells && (
                    <Card title="Earnings history">
                        <History entries={w.earnings.entries} />
                    </Card>
                )}
                <Card title="SkillPoints history">
                    {w.skillpoints.history.length === 0 ? (
                        <div className="text-sm text-slate-500">No SkillPoints activity.</div>
                    ) : (
                        <table className="w-full text-sm">
                            <tbody className="divide-y divide-slate-100">
                                {w.skillpoints.history.map((h) => (
                                    <tr key={h.id}>
                                        <td className="py-2">{h.reason}</td>
                                        <td className={`py-2 text-right tabular-nums ${h.points < 0 ? 'text-rose-700' : 'text-emerald-700'}`}>
                                            {h.points > 0 ? '+' : ''}
                                            {h.points.toLocaleString()}
                                        </td>
                                        <td className="py-2 pl-4 text-right text-slate-500">{when(h.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Card>
            </div>
        </div>
    );
}

function History({ entries }) {
    if (entries.length === 0) return <div className="text-sm text-slate-500">No earnings yet.</div>;

    const label = { held: 'Held', payable: 'Releasing', receivable: 'Owed back' };

    return (
        <table className="w-full text-sm">
            <tbody className="divide-y divide-slate-100">
                {entries.map((e) => {
                    const kind = e.account.split(':')[0];
                    // For held/payable (liabilities) a credit adds to the balance; for receivable a debit does.
                    const amount = kind === 'receivable' ? e.debit_sen - e.credit_sen : e.credit_sen - e.debit_sen;
                    return (
                        <tr key={e.id}>
                            <td className="py-2">
                                {e.order_id ? (
                                    <Link to={`/orders/${e.order_id}`} className="text-indigo-700 hover:underline">
                                        {e.description}
                                    </Link>
                                ) : (
                                    e.description
                                )}
                                <div className="text-xs text-slate-500">{label[kind]}</div>
                            </td>
                            <td className={`py-2 text-right tabular-nums ${amount < 0 ? 'text-slate-500' : 'text-emerald-700'}`}>
                                {amount > 0 ? '+' : '−'}
                                {rm(Math.abs(amount))}
                            </td>
                            <td className="py-2 pl-4 text-right text-slate-500">{when(e.created_at)}</td>
                        </tr>
                    );
                })}
            </tbody>
        </table>
    );
}
