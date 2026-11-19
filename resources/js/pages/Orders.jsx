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
        if (o.buyer?.id === me.id) return 'You bought';
        if (o.beneficiary?.id === me.id) return 'Bought for you';
        if (o.splits.some((s) => s.recipient?.id === me.id)) return 'You sell';
        return '';
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
                                <th className="py-2 pr-4 text-right font-medium">Paid</th>
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
                                    <td className="py-2 pr-4 text-right tabular-nums">{rm(o.amount_sen)}</td>
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
