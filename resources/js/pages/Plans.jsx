import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, rm, when, GATEWAY_LABEL } from '../lib';
import { Badge, Button, Card, ErrorNote } from '../ui';

const FEATURE_TEXT = {
    demand_marketplace: { state: 'Demand Marketplace: training requests in your state', all: 'Demand Marketplace: training requests in every state', none: null },
    can_list_hrdc: { true: 'List HRDC-claimable programmes' },
    career_path: { true: 'Custom career paths for staff' },
    staff_competency: { true: 'Staff core competency tracking' },
    online_support: { true: 'Online support' },
};

const featureLines = (features) =>
    Object.entries(features ?? {})
        .map(([key, value]) => FEATURE_TEXT[key]?.[String(value)])
        .filter(Boolean);

// FPX is single-use: it can pay an invoice but can't be charged again automatically.
const COLLECTION_HELP = {
    card: 'Card, renewed automatically every month.',
    invoice: 'Invoice each month, paid by FPX or card. Not paid by the period end: the plan stays on for a grace period, then ends.',
};

export default function Plans() {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [gateway, setGateway] = useState('fake');
    const [collection, setCollection] = useState('invoice');
    const [params] = useSearchParams();

    const load = () => api('/subscriptions').then(setData).catch((e) => setError(e.message));
    useEffect(() => {
        load();
    }, []);

    // The webhook can land a moment after the browser comes back from the gateway.
    useEffect(() => {
        if (!params.get('returned') || data?.subscription?.status !== 'pending') return;
        const timer = setTimeout(load, 2000);
        return () => clearTimeout(timer);
    }, [data]);

    const subscribe = async (plan) => {
        setBusy(true);
        setError(null);
        try {
            const res = await api('/subscriptions', { method: 'POST', body: { plan_id: plan.id, gateway, collection } });
            window.location.href = res.checkout_url;
        } catch (e) {
            setError(e.message);
            setBusy(false);
        }
    };

    const cancel = async () => {
        if (!confirm('Cancel your plan? It stays on until the end of the period you paid for.')) return;
        setBusy(true);
        try {
            await api(`/subscriptions/${data.subscription.id}/cancel`, { method: 'POST' });
            await load();
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    };

    if (!data) return error ? <ErrorNote error={error} /> : <div className="text-slate-500">Loading…</div>;

    const sub = data.subscription;
    const onPlan = sub && ['active', 'past_due'].includes(sub.status);

    return (
        <div className="space-y-4">
            <div>
                <h1 className="text-xl font-semibold">Plans</h1>
                <p className="text-sm text-slate-500">Subscription fees are SkillMatch's own revenue. Prices come from the client's Tier Plan and can change without code.</p>
            </div>
            <ErrorNote error={error} />

            {sub && (
                <Card title="Your plan">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="space-y-1 text-sm">
                            <div className="flex items-center gap-2 text-base font-medium">
                                {sub.plan.name} <Badge status={sub.status} />
                            </div>
                            <div className="text-slate-600">
                                {rm(sub.price_sen)} / {sub.plan.interval} · {GATEWAY_LABEL[sub.gateway] ?? sub.gateway} · {sub.collection === 'card' ? 'card, auto-renew' : 'invoice each period'}
                            </div>
                            {sub.current_period_end && (
                                <div className="text-slate-600">
                                    {sub.cancel_at_period_end ? 'Ends' : 'Renews'} {when(sub.current_period_end)}
                                </div>
                            )}
                            {sub.status === 'past_due' && <div className="text-amber-700">Renewal not paid. Your plan stays on until {when(sub.grace_ends_at)}.</div>}
                            {sub.status === 'pending' && <div className="text-slate-600">Waiting for the first payment.</div>}
                        </div>
                        <div className="flex gap-2">
                            {sub.pay_url && <Button onClick={() => (window.location.href = sub.pay_url)}>Pay invoice</Button>}
                            {!sub.cancel_at_period_end && (
                                <Button variant="secondary" disabled={busy} onClick={cancel}>
                                    {sub.status === 'pending' ? 'Abandon' : 'Cancel plan'}
                                </Button>
                            )}
                        </div>
                    </div>
                </Card>
            )}

            <Card title="What you can do now">
                <ul className="list-inside list-disc text-sm text-slate-700">
                    {featureLines(data.features).length ? featureLines(data.features).map((f) => <li key={f}>{f}</li>) : <li>Free level</li>}
                </ul>
            </Card>

            {!onPlan && (
                <Card title="Choose a plan">
                    <div className="mb-4 grid gap-3 sm:grid-cols-2">
                        <label className="block text-sm">
                            <span className="text-slate-600">Gateway</span>
                            <select value={gateway} onChange={(e) => setGateway(e.target.value)} className="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-1.5">
                                {data.gateways.map((g) => (
                                    <option key={g} value={g}>
                                        {GATEWAY_LABEL[g] ?? g}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="block text-sm">
                            <span className="text-slate-600">Pay by</span>
                            <select value={collection} onChange={(e) => setCollection(e.target.value)} className="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-1.5">
                                <option value="invoice">Invoice (FPX or card)</option>
                                <option value="card">Card, auto-renew</option>
                            </select>
                        </label>
                    </div>
                    <p className="mb-4 text-xs text-slate-500">{COLLECTION_HELP[collection]}</p>

                    <div className="grid gap-4 md:grid-cols-2">
                        {data.plans.map((plan) => {
                            const tooSmall = plan.max_staff !== null && data.staff_count > plan.max_staff;
                            return (
                                <div key={plan.id} className="rounded-lg p-4 ring-1 ring-slate-200">
                                    <div className="font-medium">{plan.name}</div>
                                    <div className="text-lg font-semibold tabular-nums">
                                        {rm(plan.price_sen)} <span className="text-sm font-normal text-slate-500">/ {plan.interval}</span>
                                    </div>
                                    <ul className="mt-2 list-inside list-disc text-sm text-slate-600">
                                        {featureLines(plan.features).map((f) => (
                                            <li key={f}>{f}</li>
                                        ))}
                                    </ul>
                                    <Button className="mt-3" disabled={busy || tooSmall} onClick={() => subscribe(plan)}>
                                        {tooSmall ? `Covers up to ${plan.max_staff} staff` : 'Subscribe'}
                                    </Button>
                                </div>
                            );
                        })}
                    </div>
                </Card>
            )}

            {data.invoices.length > 0 && (
                <Card title="Invoices and receipts">
                    <table className="w-full text-sm">
                        <tbody className="divide-y divide-slate-100">
                            {data.invoices.map((i) => (
                                <tr key={i.id}>
                                    <td className="py-2 font-mono text-xs">{i.number ?? '—'}</td>
                                    <td className="py-2">{i.plan}</td>
                                    <td className="py-2 text-slate-500">
                                        {when(i.period_start)} to {when(i.period_end)}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">{rm(i.amount_sen)}</td>
                                    <td className="py-2 pl-4">
                                        <Badge status={i.status} />
                                    </td>
                                    <td className="py-2 text-right">
                                        {i.pay_url && (
                                            <a href={i.pay_url} className="text-indigo-700 hover:underline">
                                                Pay
                                            </a>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            )}
        </div>
    );
}
