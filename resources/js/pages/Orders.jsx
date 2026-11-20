import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useApp } from '../context';
import { api, rm, when } from '../lib';
import { Badge, Card } from '../ui';

export default function Orders() {
    const { me } = useApp();
    const [orders, setOrders] = useState(null);

    useEffect(() => {
        api('/orders').then(setOrders);
    }, []);

    const relation = (o) => {
        if (o.view_as === 'buyer') return 'You bought';
        if (o.view_as === 'trainer') return 'You sell';
        if (o.beneficiary?.id === me.id) return 'Bought for you';
        return '';
    };

    // Each person sees only their own figure: the buyer what they paid, the trainer what they receive.
    const amount = (o) => {
        if (o.view_as === 'trainer') return { value: rm(o.my_amount_sen), hint: 'you receive' };
        if (o.amount_sen !== undefined) return { value: rm(o.amount_sen), hint: o.view_as === 'buyer' ? 'you paid' : 'paid' };
        return { value: '—', hint: '' };
    };

    return (
        <Card title="Orders">
            {!orders ? (
                <div className="text-sm text-slate-500">Loading…</div>
            ) : orders.length === 0 ? (
                <div className="text-sm text-slate-500">No orders yet.</div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-left text-slate-500">
                            <tr>
                                <th className="py-2 pr-4 font-medium">Order</th>
                                <th className="py-2 pr-4 font-medium">Training</th>
                                <th className="py-2 pr-4 font-medium">Buyer</th>
                                <th className="py-2 pr-4 font-medium">Mode</th>
                                <th className="py-2 pr-4 text-right font-medium">Amount</th>
                                <th className="py-2 pr-4 font-medium">Status</th>
                                <th className="py-2 font-medium">Created</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {orders.map((o) => (
                                <tr key={o.id} className="hover:bg-slate-50">
                                    <td className="py-2 pr-4">
                                        <Link to={`/orders/${o.id}`} className="font-medium text-indigo-700 hover:underline">
                                            {o.reference}
                                        </Link>
                                        <div className="text-xs text-slate-500">{relation(o)}</div>
                                    </td>
                                    <td className="py-2 pr-4">{o.training.title}</td>
                                    <td className="py-2 pr-4">
                                        {o.buyer.name}
                                        {o.beneficiary && <div className="text-xs text-slate-500">for {o.beneficiary.name}</div>}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {o.payout_mode} · {o.gateway}
                                    </td>
                                    <td className="py-2 pr-4 text-right tabular-nums">
                                        {amount(o).value}
                                        <div className="text-xs text-slate-500">{amount(o).hint}</div>
                                    </td>
                                    <td className="py-2 pr-4">
                                        <Badge status={o.status} />
                                    </td>
                                    <td className="py-2 text-slate-500">{when(o.timestamps.created_at)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}
