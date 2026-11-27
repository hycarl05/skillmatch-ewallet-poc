// Small helpers shared by every page.

export const getUserId = () => localStorage.getItem('demoUserId');
export const setUserId = (id) => localStorage.setItem('demoUserId', String(id));

export async function api(path, { method = 'GET', body } = {}) {
    const res = await fetch(`/api${path}`, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Demo-User': getUserId() ?? '',
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        const error = new Error(first || data.message || `Request failed (${res.status})`);
        error.status = res.status;
        error.data = data;
        throw error;
    }
    return data;
}

// All money is integer sen. Format only at the edge.
export const rm = (sen) =>
    'RM ' + ((sen ?? 0) / 100).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const when = (iso) => (iso ? new Date(iso).toLocaleString('en-MY', { dateStyle: 'medium', timeStyle: 'short' }) : '—');

export const ROLE_LABEL = {
    individual: 'Individual',
    company_hr: 'Company HR',
    staff: 'Staff',
    provider: 'Training provider',
    freelancer: 'Freelance trainer',
    platform: 'SkillMatch admin',
};

export const GATEWAY_LABEL = {
    fake: 'FakePay',
    billplz: 'Billplz',
    chip: 'CHIP',
    stripe: 'Stripe',
};

// "4.2%" — share of a total, one decimal, without trailing ".0".
export const pct = (part, total) => (total ? `${+((part / total) * 100).toFixed(1)}%` : '—');

export const canBuy = (role) => ['individual', 'company_hr', 'provider'].includes(role);
