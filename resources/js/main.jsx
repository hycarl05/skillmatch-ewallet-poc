import { StrictMode, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { AppContext } from './context';
import { api, canBuy, getUserId, ROLE_LABEL, setUserId } from './lib';
import Catalogue from './pages/Catalogue';
import Orders from './pages/Orders';
import OrderDetail from './pages/OrderDetail';
import Wallet from './pages/Wallet';
import Platform from './pages/Platform';
import Dashboard from './pages/Dashboard';

function App() {
    const [users, setUsers] = useState([]);
    const [config, setConfig] = useState(null);
    const [userId, setCurrent] = useState(getUserId());
    const [error, setError] = useState(null);

    useEffect(() => {
        Promise.all([api('/demo/users'), api('/config')])
            .then(([u, c]) => {
                setUsers(u);
                setConfig(c);
                if (!u.find((x) => String(x.id) === String(getUserId())) && u[1]) {
                    setUserId(u[1].id);
                    setCurrent(String(u[1].id));
                }
            })
            .catch((e) => setError(e.message));
    }, []);

    const me = users.find((u) => String(u.id) === String(userId));

    if (error) {
        return <div className="p-8 text-rose-700">Could not load the app: {error}. Did you run <code>php artisan migrate --seed</code>?</div>;
    }
    if (!me || !config) return <div className="p-8 text-slate-500">Loading…</div>;

    const switchUser = (id) => {
        setUserId(id);
        setCurrent(String(id));
    };

    const isAdmin = me.role === 'platform';
    const nav = [
        isAdmin && ['/dashboard', 'Dashboard'],
        canBuy(me.role) && ['/', 'Trainings'],
        ['/orders', 'Orders'],
        ['/wallet', 'Wallet'],
        me.role === 'platform' && ['/ledger', 'Platform ledger'],
    ].filter(Boolean);

    return (
        <AppContext.Provider value={{ me, users, config }}>
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-3">
                    <div className="mr-4">
                        <div className="font-semibold text-indigo-700">SkillMatch e-Wallet</div>
                        <div className="text-xs text-slate-500">Proof of concept · sandbox only</div>
                    </div>
                    <nav className="flex gap-1">
                        {nav.map(([to, label]) => (
                            <NavLink
                                key={to}
                                to={to}
                                end
                                className={({ isActive }) =>
                                    `rounded-lg px-3 py-1.5 text-sm ${isActive ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-600 hover:bg-slate-100'}`
                                }
                            >
                                {label}
                            </NavLink>
                        ))}
                    </nav>
                    <label className="ml-auto flex items-center gap-2 text-sm">
                        <span className="text-slate-500">Acting as</span>
                        <select
                            value={me.id}
                            onChange={(e) => switchUser(e.target.value)}
                            className="rounded-lg border border-slate-300 bg-white px-2 py-1.5"
                        >
                            {users.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.name} — {ROLE_LABEL[u.role]}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-4 py-6" key={me.id}>
                <Routes>
                    <Route path="/" element={canBuy(me.role) ? <Catalogue /> : <Navigate to={isAdmin ? '/dashboard' : '/orders'} replace />} />
                    <Route path="/dashboard" element={isAdmin ? <Dashboard /> : <Navigate to="/" replace />} />
                    <Route path="/orders" element={<Orders />} />
                    <Route path="/orders/:id" element={<OrderDetail />} />
                    <Route path="/wallet" element={<Wallet />} />
                    <Route path="/ledger" element={<Platform />} />
                </Routes>
            </main>
        </AppContext.Provider>
    );
}

createRoot(document.getElementById('app')).render(
    <StrictMode>
        <BrowserRouter>
            <App />
        </BrowserRouter>
    </StrictMode>,
);
