import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { BarList, ChartTable, ColumnChart } from '../charts';
import { api, GATEWAY_LABEL, rm, when } from '../lib';
import { Badge, Card, ErrorNote } from '../ui';

const RANGES = [
    [7, '7 days'],
    [30, '30 days'],
    [90, '90 days'],
    [365, '12 months'],
];

const count = (n) => n.toLocaleString('en-MY');

/** "RM 1.2k" for axis labels, where full amounts would crowd the chart. */
const rmShort = (sen) => {
    const v = sen / 100;
    if (v >= 1_000_000) return `RM ${+(v / 1_000_000).toFixed(1)}m`;
    if (v >= 1_000) return `RM ${+(v / 1_000).toFixed(1)}k`;
    return `RM ${+v.toFixed(0)}`;
};

const METRICS = {
    gmv: { label: 'Collected', hint: 'Money buyers paid through the gateway', value: (o) => o.amount_sen, format: rm, axis: rmShort },
    commission: { label: 'SkillMatch commission', hint: 'Commission after SkillPoints discounts and refunds', value: (o) => o.commission_sen, format: rm, axis: rmShort },
    refunded: { label: 'Refunded', hint: 'Refunds given or owed to buyers', value: (o) => o.refunded_sen, format: rm, axis: rmShort },
    orders: { label: 'Orders', hint: 'Every order placed, including failed payments', value: () => 1, format: count, axis: count },
};

const DIMENSIONS = {
    training: { label: 'Training', get: (o) => o.training },
    seller: { label: 'Trainer / provider', get: (o) => o.seller },
    buyer: { label: 'Buyer', get: (o) => o.buyer },
    gateway: { label: 'Gateway', get: (o) => GATEWAY_LABEL[o.gateway] ?? o.gateway },
    status: { label: 'Status', get: (o) => o.status.replaceAll('_', ' ') },
};

// ---- time buckets -----------------------------------------------------------

const DAY_MS = 86_400_000;
const fmtDay = (iso, opts) => new Date(`${iso}T00:00:00Z`).toLocaleDateString('en-MY', { timeZone: 'UTC', ...opts });

/** One bucket per day (≤ 90 days) or per month (12 months), including empty ones so gaps show. */
function buckets(range) {
    const monthly = range.days > 90;
    const out = [];
    const end = Date.parse(`${range.to}T00:00:00Z`);
    for (let t = Date.parse(`${range.from}T00:00:00Z`); t <= end; t += DAY_MS) {
        const iso = new Date(t).toISOString().slice(0, 10);
        const key = monthly ? iso.slice(0, 7) : iso;
        if (out.at(-1)?.key === key) continue;
        out.push({
            key,
            label: monthly ? fmtDay(`${key}-01`, { month: 'long', year: 'numeric' }) : fmtDay(iso, { weekday: 'short', day: 'numeric', month: 'short' }),
            short: monthly ? fmtDay(`${key}-01`, { month: 'short' }) : fmtDay(iso, { day: 'numeric', month: 'short' }),
        });
    }
    return out;
}

// ---- drill-down path --------------------------------------------------------
// The path is a list of filters, e.g. [{kind:'bucket', key:'2026-11-20'}, {kind:'dim', dim:'training', value:'Excel…'}].
// Empty → bars over time. Ends with a time filter → breakdown by a dimension. Ends with a dimension → the orders.

const matches = (o, step) =>
    step.kind === 'all' || (step.kind === 'bucket' ? o.day.startsWith(step.key) : DIMENSIONS[step.dim].get(o) === step.value);

function groupBy(orders, getKey, metric) {
    const map = new Map();
    for (const o of orders) {
        const k = getKey(o);
        const row = map.get(k) ?? { key: k, label: k, value: 0, n: 0 };
        row.value += METRICS[metric].value(o);
        row.n += 1;
        map.set(k, row);
    }
    return [...map.values()].sort((a, b) => b.value - a.value || b.n - a.n);
}

// ---- page -------------------------------------------------------------------

export default function Dashboard() {
    const [days, setDays] = useState(30);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [metric, setMetric] = useState('gmv');
    const [dim, setDim] = useState('training');
    const [path, setPath] = useState([]);
    const [asTable, setAsTable] = useState(false);

    useEffect(() => {
        setError(null);
        api(`/platform/dashboard?days=${days}`)
            .then((d) => {
                setData(d);
                setPath([]);
            })
            .catch((e) => setError(e.message));
    }, [days]);

    const orders = data?.orders ?? [];
    const filtered = useMemo(() => orders.filter((o) => path.every((s) => matches(o, s))), [orders, path]);

    if (error) return <ErrorNote error={error} />;
    if (!data) return <div className="text-slate-500">Loading…</div>;

    const M = METRICS[metric];
    const sum = (fn) => orders.reduce((t, o) => t + fn(o), 0);
    const paidCount = orders.filter((o) => o.paid).length;
    const periodLabel = RANGES.find(([d]) => d === days)[1];

    const last = path.at(-1);
    const view = !last ? 'time' : last.kind === 'dim' ? 'orders' : 'groups';

    const timeData = buckets(data.range).map((b) => ({
        ...b,
        value: orders.filter((o) => o.day.startsWith(b.key)).reduce((t, o) => t + M.value(o), 0),
    }));
    const groupData = groupBy(filtered, DIMENSIONS[dim].get, metric).map((g) => ({ ...g, hint: `${g.n} order${g.n === 1 ? '' : 's'}` }));

    const drillTo = (steps) => {
        setPath(steps);
        setAsTable(false);
    };
    const pickBucket = (b) => drillTo([{ kind: 'bucket', key: b.key, label: b.label }]);
    const pickGroup = (g) => drillTo([...path, { kind: 'dim', dim, value: g.key, label: `${DIMENSIONS[dim].label}: ${g.key}` }]);
    const showAllFor = (d, value) =>
        drillTo([
            { kind: 'all', label: `Last ${periodLabel}` },
            { kind: 'dim', dim: d, value, label: `${DIMENSIONS[d].label}: ${value}` },
        ]);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold text-slate-900">Dashboard</h1>
                    <p className="text-sm text-slate-500">
                        {fmtDay(data.range.from, { day: 'numeric', month: 'short', year: 'numeric' })} –{' '}
                        {fmtDay(data.range.to, { day: 'numeric', month: 'short', year: 'numeric' })} · Malaysian time · click any bar to break it down
                    </p>
                </div>
                <Segmented options={RANGES} value={days} onChange={setDays} label="Period" />
            </div>

            <Attention a={data.attention} />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                {Object.entries(METRICS).map(([key, m]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => setMetric(key)}
                        aria-pressed={metric === key}
                        className={`rounded-xl border bg-white p-4 text-left transition ${metric === key ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-slate-200 hover:border-slate-300'}`}
                    >
                        <div className="text-sm text-slate-500">{m.label}</div>
                        <div className="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">{m.format(sum(m.value))}</div>
                        <div className="mt-1 text-xs text-slate-500">
                            {key === 'orders' ? `${paidCount} paid · ${orders.length - paidCount} unpaid or failed` : m.hint}
                        </div>
                    </button>
                ))}
            </div>

            <Card
                title={
                    <Breadcrumb
                        root={`${M.label} by ${data.range.days > 90 ? 'month' : 'day'}`}
                        path={path}
                        onJump={(i) => drillTo(path.slice(0, i))}
                    />
                }
                action={
                    view !== 'orders' && (
                        <button type="button" onClick={() => setAsTable((t) => !t)} className="text-sm text-indigo-700 hover:underline">
                            {asTable ? 'Show chart' : 'Show table'}
                        </button>
                    )
                }
            >
                {view === 'time' && (
                    <>
                        {asTable ? (
                            <ChartTable data={timeData} format={M.format} labelHeader={data.range.days > 90 ? 'Month' : 'Day'} valueHeader={M.label} />
                        ) : (
                            <ColumnChart data={timeData} format={M.format} axisFormat={M.axis} onSelect={pickBucket} unit={`${M.label} over time`} />
                        )}
                        <div className="mt-3 flex justify-end">
                            <button type="button" onClick={() => drillTo([{ kind: 'all', label: `Last ${periodLabel}` }])} className="text-sm text-indigo-700 hover:underline">
                                Break down the whole period →
                            </button>
                        </div>
                    </>
                )}

                {view === 'groups' && (
                    <>
                        <div className="mb-3 flex flex-wrap items-center gap-2 text-sm">
                            <span className="text-slate-500">Break down by</span>
                            <Segmented options={Object.entries(DIMENSIONS).map(([k, d]) => [k, d.label])} value={dim} onChange={setDim} label="Break down by" small />
                        </div>
                        {asTable ? (
                            <ChartTable data={groupData} format={M.format} labelHeader={DIMENSIONS[dim].label} valueHeader={M.label} />
                        ) : (
                            <BarList data={groupData} format={M.format} onSelect={pickGroup} />
                        )}
                    </>
                )}

                {view === 'orders' && <OrderTable orders={filtered} />}
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="Orders by status">
                    <BarList data={groupBy(orders, DIMENSIONS.status.get, 'orders')} format={count} onSelect={(g) => showAllFor('status', g.key)} />
                </Card>
                <Card title="Collected by gateway">
                    <BarList data={groupBy(orders, DIMENSIONS.gateway.get, 'gmv')} format={rm} onSelect={(g) => showAllFor('gateway', g.key)} />
                </Card>
                <Card title="Top trainings by money collected">
                    <BarList data={groupBy(orders, DIMENSIONS.training.get, 'gmv').slice(0, 5)} format={rm} onSelect={(g) => showAllFor('training', g.key)} />
                </Card>
                <Card title="Top trainers / providers by money collected">
                    <BarList data={groupBy(orders, DIMENSIONS.seller.get, 'gmv').slice(0, 5)} format={rm} onSelect={(g) => showAllFor('seller', g.key)} />
                </Card>
            </div>
        </div>
    );
}

// ---- pieces -----------------------------------------------------------------

function Segmented({ options, value, onChange, label, small = false }) {
    return (
        <div role="radiogroup" aria-label={label} className="inline-flex flex-wrap rounded-lg bg-slate-100 p-0.5">
            {options.map(([v, text]) => (
                <button
                    key={v}
                    type="button"
                    role="radio"
                    aria-checked={value === v}
                    onClick={() => onChange(v)}
                    className={`rounded-md ${small ? 'px-2 py-1 text-xs' : 'px-3 py-1.5 text-sm'} ${value === v ? 'bg-white font-medium text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
                >
                    {text}
                </button>
            ))}
        </div>
    );
}

function Breadcrumb({ root, path, onJump }) {
    return (
        <span className="flex flex-wrap items-center gap-1 text-base">
            {path.length ? (
                <button type="button" onClick={() => onJump(0)} className="text-indigo-700 hover:underline">
                    {root}
                </button>
            ) : (
                <span>{root}</span>
            )}
            {path.map((s, i) => (
                <span key={i} className="flex items-center gap-1">
                    <span className="text-slate-400" aria-hidden>
                        ›
                    </span>
                    {i < path.length - 1 ? (
                        <button type="button" onClick={() => onJump(i + 1)} className="text-indigo-700 hover:underline">
                            {s.label}
                        </button>
                    ) : (
                        <span aria-current="page">{s.label}</span>
                    )}
                </span>
            ))}
        </span>
    );
}

/** Things waiting for SkillMatch. Zero reads as calm; anything else says what to do. */
function Attention({ a }) {
    const items = [
        { label: 'Refund requests to decide', value: count(a.refund_requests), alert: a.refund_requests > 0 },
        { label: 'Refunds owed to buyers', value: count(a.refunds_owed), sub: a.refunds_owed ? rm(a.refunds_owed_sen) : null, alert: a.refunds_owed > 0 },
        { label: 'Shares to claw back', value: count(a.clawbacks), alert: a.clawbacks > 0 },
        { label: 'Open chargebacks', value: count(a.open_disputes), alert: a.open_disputes > 0 },
        { label: 'Held for trainers', value: rm(a.held_sen), sub: 'Released when training is confirmed' },
        { label: 'Ledger', value: a.books_balanced ? 'Balanced' : 'NOT balanced', alert: !a.books_balanced },
    ];

    return (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            {items.map((i) => (
                <Link
                    key={i.label}
                    to="/ledger"
                    className={`rounded-xl border p-3 transition hover:shadow-sm ${i.alert ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white'}`}
                >
                    <div className="flex items-center gap-1.5 text-xs text-slate-600">
                        {i.alert && (
                            <span aria-hidden className="text-amber-600">
                                ●
                            </span>
                        )}
                        {i.label}
                    </div>
                    <div className={`mt-1 text-lg font-semibold tabular-nums ${i.alert ? 'text-amber-900' : 'text-slate-900'}`}>{i.value}</div>
                    <div className="text-xs text-slate-500">{i.alert ? 'Needs action →' : (i.sub ?? 'Nothing to do')}</div>
                </Link>
            ))}
        </div>
    );
}

function OrderTable({ orders }) {
    if (!orders.length) return <div className="py-6 text-center text-sm text-slate-500">No orders match.</div>;

    const total = (k) => orders.reduce((t, o) => t + o[k], 0);

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="text-left text-slate-500">
                    <tr>
                        <th className="py-2 pr-4 font-medium">Order</th>
                        <th className="py-2 pr-4 font-medium">Training</th>
                        <th className="py-2 pr-4 font-medium">Status</th>
                        <th className="py-2 pr-4 text-right font-medium">Collected</th>
                        <th className="py-2 pr-4 text-right font-medium">Refunded</th>
                        <th className="py-2 text-right font-medium">Commission</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 tabular-nums">
                    {orders.map((o) => (
                        <tr key={o.id} className="hover:bg-slate-50">
                            <td className="py-2 pr-4">
                                <Link to={`/orders/${o.id}`} className="font-medium text-indigo-700 hover:underline">
                                    {o.reference}
                                </Link>
                                <div className="text-xs text-slate-500">
                                    {when(o.at)} · {o.buyer}
                                </div>
                            </td>
                            <td className="py-2 pr-4">
                                {o.training}
                                <div className="text-xs text-slate-500">
                                    {o.seller} · {GATEWAY_LABEL[o.gateway] ?? o.gateway} · {o.payout_mode}
                                </div>
                            </td>
                            <td className="py-2 pr-4">
                                <Badge status={o.status} />
                            </td>
                            <td className="py-2 pr-4 text-right">{rm(o.amount_sen)}</td>
                            <td className="py-2 pr-4 text-right">{o.refunded_sen ? rm(o.refunded_sen) : '—'}</td>
                            <td className="py-2 text-right">{rm(o.commission_sen)}</td>
                        </tr>
                    ))}
                </tbody>
                <tfoot className="border-t border-slate-200 font-medium tabular-nums">
                    <tr>
                        <td className="py-2 pr-4" colSpan={3}>
                            {orders.length} order{orders.length === 1 ? '' : 's'}
                        </td>
                        <td className="py-2 pr-4 text-right">{rm(total('amount_sen'))}</td>
                        <td className="py-2 pr-4 text-right">{rm(total('refunded_sen'))}</td>
                        <td className="py-2 text-right">{rm(total('commission_sen'))}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}
