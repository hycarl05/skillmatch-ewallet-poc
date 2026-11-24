const STATUS_STYLE = {
    pending_payment: 'bg-amber-50 text-amber-800 ring-amber-200',
    pending: 'bg-slate-100 text-slate-700 ring-slate-200',
    paid: 'bg-sky-50 text-sky-800 ring-sky-200',
    held: 'bg-amber-50 text-amber-800 ring-amber-200',
    completed: 'bg-violet-50 text-violet-800 ring-violet-200',
    released: 'bg-violet-50 text-violet-800 ring-violet-200',
    confirmed: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    paid_out: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    failed: 'bg-rose-50 text-rose-800 ring-rose-200',
    refunded: 'bg-slate-100 text-slate-700 ring-slate-300',
    reversed: 'bg-slate-100 text-slate-700 ring-slate-300',
    clawback_required: 'bg-rose-50 text-rose-800 ring-rose-200',
    // refunds
    requested: 'bg-amber-50 text-amber-800 ring-amber-200',
    rejected: 'bg-slate-100 text-slate-700 ring-slate-300',
    processing: 'bg-sky-50 text-sky-800 ring-sky-200',
    succeeded: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    manual_required: 'bg-amber-50 text-amber-800 ring-amber-200',
    // disputes
    open: 'bg-rose-50 text-rose-800 ring-rose-200',
    won: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    lost: 'bg-rose-50 text-rose-800 ring-rose-200',
};

export function Badge({ status }) {
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_STYLE[status] ?? STATUS_STYLE.pending}`}>
            {status.replaceAll('_', ' ')}
        </span>
    );
}

export function Card({ title, action, children, className = '' }) {
    return (
        <section className={`rounded-xl border border-slate-200 bg-white ${className}`}>
            {(title || action) && (
                <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <h2 className="font-semibold text-slate-800">{title}</h2>
                    {action}
                </div>
            )}
            <div className="p-4">{children}</div>
        </section>
    );
}

export function Button({ variant = 'primary', className = '', ...props }) {
    const styles = {
        primary: 'bg-indigo-600 text-white hover:bg-indigo-700',
        secondary: 'bg-white text-slate-800 ring-1 ring-slate-300 hover:bg-slate-50',
        danger: 'bg-white text-rose-700 ring-1 ring-rose-300 hover:bg-rose-50',
    };
    return (
        <button
            className={`rounded-lg px-3 py-1.5 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50 ${styles[variant]} ${className}`}
            {...props}
        />
    );
}

export function ErrorNote({ error }) {
    if (!error) return null;
    return <div className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">{error}</div>;
}

export function Stat({ label, value, hint }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="text-sm text-slate-500">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums">{value}</div>
            {hint && <div className="mt-1 text-xs text-slate-500">{hint}</div>}
        </div>
    );
}
