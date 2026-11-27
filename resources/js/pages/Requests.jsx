import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useApp } from '../context';
import { api, rm, when } from '../lib';
import { Button, Card, ErrorNote } from '../ui';

const STATES = ['Johor', 'Kedah', 'Kelantan', 'Kuala Lumpur', 'Labuan', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis', 'Pulau Pinang', 'Putrajaya', 'Sabah', 'Sarawak', 'Selangor', 'Terengganu'];

/** Demand Marketplace: buyers post what they need; sellers with a plan see it. */
export default function Requests() {
    const { me } = useApp();
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const isBuyer = ['individual', 'company_hr'].includes(me.role);

    const load = () => api('/training-requests').then(setData).catch((e) => setError(e.message));
    useEffect(() => {
        load();
    }, []);

    if (!data) return error ? <ErrorNote error={error} /> : <div className="text-slate-500">Loading…</div>;

    return (
        <div className="space-y-4">
            <div>
                <h1 className="text-xl font-semibold">Demand Marketplace</h1>
                <p className="text-sm text-slate-500">
                    {isBuyer
                        ? 'Tell training providers and freelance trainers what you need.'
                        : data.scope === 'state'
                          ? `Training requests in ${data.state}. Tier 2 shows every state.`
                          : 'Training requests from individuals and companies.'}
                </p>
            </div>
            <ErrorNote error={error} />

            {isBuyer && <NewRequest onCreated={load} />}

            {data.scope === 'none' ? (
                <Card>
                    <p className="text-sm text-slate-700">
                        {data.upgrade} {data.hidden_count > 0 && <strong>{data.hidden_count} open requests are waiting.</strong>}
                    </p>
                    <Link to="/plans" className="mt-3 inline-block text-sm font-medium text-indigo-700 hover:underline">
                        See plans →
                    </Link>
                </Card>
            ) : (
                <Card title={isBuyer ? 'Your requests' : 'Open requests'}>
                    {data.requests.length === 0 ? (
                        <div className="text-sm text-slate-500">Nothing here yet.</div>
                    ) : (
                        <table className="w-full text-sm">
                            <tbody className="divide-y divide-slate-100">
                                {data.requests.map((r) => (
                                    <tr key={r.id}>
                                        <td className="py-2">
                                            <div className="font-medium">{r.title}</div>
                                            <div className="text-xs text-slate-500">
                                                {r.requester.name} · {r.participants} participant{r.participants === 1 ? '' : 's'}
                                            </div>
                                        </td>
                                        <td className="py-2">{r.state}</td>
                                        <td className="py-2 text-right tabular-nums">{r.budget_sen ? rm(r.budget_sen) : '—'}</td>
                                        <td className="py-2 text-right text-slate-500">{when(r.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                    {data.hidden_count > 0 && (
                        <p className="mt-3 text-xs text-slate-500">
                            {data.hidden_count} more in other states. <Link to="/plans" className="text-indigo-700 hover:underline">Upgrade to Tier 2</Link> to see them.
                        </p>
                    )}
                </Card>
            )}
        </div>
    );
}

function NewRequest({ onCreated }) {
    const { me } = useApp();
    const [form, setForm] = useState({ title: '', state: me.state ?? 'Selangor', budget: '', participants: 1 });
    const [error, setError] = useState(null);
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setError(null);
        try {
            await api('/training-requests', {
                method: 'POST',
                body: {
                    title: form.title,
                    state: form.state,
                    budget_sen: form.budget ? Math.round(Number(form.budget) * 100) : null,
                    participants: Number(form.participants) || 1,
                },
            });
            setForm({ ...form, title: '', budget: '' });
            onCreated();
        } catch (err) {
            setError(err.message);
        }
    };

    return (
        <Card title="Post a training request">
            <form onSubmit={submit} className="grid gap-3 sm:grid-cols-4">
                <input required placeholder="What training do you need?" value={form.title} onChange={set('title')} className="rounded-lg border border-slate-300 px-2 py-1.5 text-sm sm:col-span-2" />
                <select value={form.state} onChange={set('state')} className="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm">
                    {STATES.map((s) => (
                        <option key={s}>{s}</option>
                    ))}
                </select>
                <input type="number" min="0" placeholder="Budget (RM)" value={form.budget} onChange={set('budget')} className="rounded-lg border border-slate-300 px-2 py-1.5 text-sm" />
                <input type="number" min="1" placeholder="Participants" value={form.participants} onChange={set('participants')} className="rounded-lg border border-slate-300 px-2 py-1.5 text-sm" />
                <div className="sm:col-span-3">
                    <ErrorNote error={error} />
                </div>
                <Button type="submit" className="sm:col-span-1">
                    Post request
                </Button>
            </form>
        </Card>
    );
}
