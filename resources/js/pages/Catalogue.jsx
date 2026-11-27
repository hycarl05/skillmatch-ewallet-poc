import { useEffect, useState } from 'react';
import { useApp } from '../context';
import { api, rm } from '../lib';
import { Button, Card, ErrorNote } from '../ui';

const GATEWAY_LABEL = {
    fake: 'FakePay simulator (no keys needed)',
    billplz: 'Billplz sandbox',
    chip: 'CHIP sandbox',
    stripe: 'Stripe test mode (Connect payouts)',
};

const MODE_HELP = {
    instant: 'Gateway splits the payment at collection: trainers are paid straight away (Billplz / ToyyibPay style).',
    deferred: 'Trainer’s share is held until the training is confirmed, then paid out (Stripe Connect / Xendit / CHIP Send style).',
};

export default function Catalogue() {
    const [trainings, setTrainings] = useState([]);
    const [buying, setBuying] = useState(null);

    useEffect(() => {
        api('/trainings').then(setTrainings);
    }, []);

    return (
        <div className="space-y-4">
            <div>
                <h1 className="text-xl font-semibold">Trainings</h1>
                <p className="text-sm text-slate-500">Buy a training to walk through the payment flow. Every payment goes through the gateway; SkillMatch only records it.</p>
            </div>
            <div className="grid gap-4 md:grid-cols-2">
                {trainings.map((t) => (
                    <Card key={t.id}>
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <div className="flex items-center gap-2 font-medium">
                                    {t.title}
                                    {t.hrdc_claimable && <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-emerald-200">HRDC</span>}
                                </div>
                                <div className="text-sm text-slate-500">
                                    by {t.seller.name}
                                    {t.freelancer && <> · delivered by {t.freelancer.name}</>}
                                </div>
                                <p className="mt-2 text-sm text-slate-600">{t.description}</p>
                            </div>
                            <div className="text-right">
                                <div className="text-lg font-semibold tabular-nums">{rm(t.price_sen)}</div>
                                <Button className="mt-2" onClick={() => setBuying(t)}>
                                    Buy
                                </Button>
                            </div>
                        </div>
                        {buying?.id === t.id && <Checkout training={t} onClose={() => setBuying(null)} />}
                    </Card>
                ))}
            </div>
        </div>
    );
}

function Checkout({ training, onClose }) {
    const { me, users, config } = useApp();
    const staff = users.filter((u) => u.company_id === me.id);
    const [beneficiary, setBeneficiary] = useState(staff[0]?.id ?? '');
    const [points, setPoints] = useState(0);
    // HRDC-claimable trainings can be paid here, or booked through HRDC (HRDC pays the provider directly).
    const [funding, setFunding] = useState('platform');
    const viaHrdc = funding === 'hrdc';
    const [gateway, setGateway] = useState(config.default_gateway);
    const modes = config.gateways.find((g) => g.name === gateway)?.payout_modes ?? [];
    const [mode, setMode] = useState(modes[0]);
    const [quote, setQuote] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!modes.includes(mode)) setMode(modes[0]);
    }, [gateway]);

    useEffect(() => {
        api(`/trainings/${training.id}/quote?skillpoints=${viaHrdc ? 0 : points}&funding=${funding}`).then(setQuote).catch((e) => setError(e.message));
    }, [points, funding]);

    const pay = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await api('/orders', {
                method: 'POST',
                body: {
                    training_id: training.id,
                    beneficiary_id: me.role === 'company_hr' ? Number(beneficiary) : null,
                    skillpoints: viaHrdc ? 0 : points,
                    gateway,
                    payout_mode: mode,
                    funding,
                },
            });
            // HRDC bookings have nothing to pay here; everything else goes to the gateway's payment page.
            window.location.href = res.checkout_url ?? `/orders/${res.order.id}`;
        } catch (e) {
            setError(e.message);
            setBusy(false);
        }
    };

    return (
        <div className="mt-4 space-y-3 rounded-lg bg-slate-50 p-4 ring-1 ring-slate-200">
            {me.role === 'company_hr' && (
                <label className="block text-sm">
                    <span className="text-slate-600">Staff attending</span>
                    <select value={beneficiary} onChange={(e) => setBeneficiary(e.target.value)} className="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-1.5">
                        {staff.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                </label>
            )}

            {training.hrdc_claimable && (
                <fieldset className="space-y-1 text-sm">
                    <legend className="text-slate-600">How is this training paid?</legend>
                    <label className="flex items-center gap-2">
                        <input type="radio" checked={!viaHrdc} onChange={() => setFunding('platform')} /> Pay now through SkillMatch
                    </label>
                    <label className="flex items-center gap-2">
                        <input type="radio" checked={viaHrdc} onChange={() => setFunding('hrdc')} /> Claim through HRDC (HRDC pays the provider; nothing to pay here)
                    </label>
                </fieldset>
            )}

            {!viaHrdc && (<>
            <label className="block text-sm">
                <span className="text-slate-600">
                    SkillPoints to use (max {quote?.max_points ?? 0}, {config.sen_per_point} sen each)
                </span>
                <input
                    type="number"
                    min="0"
                    max={quote?.max_points ?? 0}
                    value={points}
                    onChange={(e) => setPoints(Math.max(0, Math.min(Number(e.target.value) || 0, quote?.max_points ?? 0)))}
                    className="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-1.5"
                />
            </label>

            <div className="grid gap-3 sm:grid-cols-2">
                <label className="block text-sm">
                    <span className="text-slate-600">Payment gateway</span>
                    <select value={gateway} onChange={(e) => setGateway(e.target.value)} className="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-1.5">
                        {config.gateways.map((g) => (
                            <option key={g.name} value={g.name}>
                                {GATEWAY_LABEL[g.name] ?? g.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="block text-sm">
                    <span className="text-slate-600">Payout mode</span>
                    <select value={mode} onChange={(e) => setMode(e.target.value)} disabled={modes.length < 2} className="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-1.5">
                        {modes.map((m) => (
                            <option key={m} value={m}>
                                {m}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            {mode && <p className="text-xs text-slate-500">{MODE_HELP[mode]}</p>}
            </>)}

            {quote && (
                <dl className="grid grid-cols-2 gap-x-4 gap-y-1 border-t border-slate-200 pt-3 text-sm tabular-nums">
                    <dt className="text-slate-500">Training price</dt>
                    <dd className="text-right">{rm(quote.gross_sen)}</dd>
                    {quote.company_discount_sen > 0 && (
                        <>
                            <dt className="text-slate-500">Company plan discount</dt>
                            <dd className="text-right">− {rm(quote.company_discount_sen)}</dd>
                        </>
                    )}
                    {!viaHrdc && (
                        <>
                            <dt className="text-slate-500">SkillPoints discount</dt>
                            <dd className="text-right">− {rm(quote.discount_sen)}</dd>
                        </>
                    )}
                    <dt className="font-medium">{viaHrdc ? 'You pay through SkillMatch' : 'You pay'}</dt>
                    <dd className="text-right font-medium">{rm(quote.amount_sen)}</dd>
                </dl>
            )}

            <ErrorNote error={error} />
            <div className="flex gap-2">
                <Button onClick={pay} disabled={busy || (me.role === 'company_hr' && !beneficiary)}>
                    {busy ? (viaHrdc ? 'Booking…' : 'Opening gateway…') : viaHrdc ? 'Book through HRDC' : `Pay ${quote ? rm(quote.amount_sen) : ''}`}
                </Button>
                <Button variant="secondary" onClick={onClose}>
                    Cancel
                </Button>
            </div>
        </div>
    );
}
