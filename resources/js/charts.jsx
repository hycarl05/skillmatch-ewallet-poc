import { useEffect, useRef, useState } from 'react';

// Small dependency-free SVG/HTML charts for the admin dashboard.
// One series per chart, so one hue: the title names what it shows.

const BAR = '#6366f1'; // indigo-500
const BAR_ACTIVE = '#4338ca'; // indigo-700

/** Round the axis maximum up to 1, 2, 2.5 or 5 × 10ⁿ so gridlines land on readable numbers. */
function niceMax(max, ticks = 4) {
    if (max <= 0) return ticks;
    const raw = max / ticks;
    const pow = 10 ** Math.floor(Math.log10(raw));
    const step = [1, 2, 2.5, 5, 10].find((m) => m * pow >= raw) * pow;
    return step * ticks;
}

function useWidth() {
    const ref = useRef(null);
    const [width, setWidth] = useState(0);
    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const ro = new ResizeObserver(([e]) => setWidth(e.contentRect.width));
        ro.observe(el);
        return () => ro.disconnect();
    }, []);
    return [ref, width];
}

/** A bar whose top corners are rounded and whose bottom sits flat on the baseline. */
function topRounded(x, y, w, h) {
    const r = Math.min(4, w / 2, h);
    return `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`;
}

/**
 * Vertical bars over time. Click (or Enter on) a bar to drill into it.
 * data: [{ key, label, value }]
 */
export function ColumnChart({ data, format, axisFormat = format, onSelect, height = 240, unit }) {
    const [ref, width] = useWidth();
    const [hover, setHover] = useState(null);

    const m = { top: 12, right: 8, bottom: 26, left: 64 };
    const innerW = Math.max(0, width - m.left - m.right);
    const innerH = height - m.top - m.bottom;
    const max = niceMax(Math.max(0, ...data.map((d) => d.value)));
    const band = data.length ? innerW / data.length : 0;
    const barW = Math.max(2, Math.min(band - 2, 28));
    const y = (v) => innerH - (v / max) * innerH;
    const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => t * max);
    const labelEvery = Math.max(1, Math.ceil(56 / Math.max(band, 1)));

    const active = hover !== null ? data[hover] : null;

    return (
        <div ref={ref} className="relative select-none">
            {width > 0 && (
                <svg width={width} height={height} role="group" aria-label={unit}>
                    <g transform={`translate(${m.left},${m.top})`}>
                        {ticks.map((t) => (
                            <g key={t} transform={`translate(0,${y(t)})`}>
                                <line x2={innerW} stroke={t === 0 ? '#94a3b8' : '#e2e8f0'} />
                                <text x={-8} dy="0.32em" textAnchor="end" className="fill-slate-500 text-[11px] tabular-nums">
                                    {axisFormat(t)}
                                </text>
                            </g>
                        ))}
                        {data.map((d, i) => {
                            const x = i * band;
                            const h = innerH - y(d.value);
                            const clickable = onSelect && d.value > 0;
                            const on = hover === i;
                            return (
                                <g
                                    key={d.key}
                                    tabIndex={clickable ? 0 : -1}
                                    role={clickable ? 'button' : undefined}
                                    aria-label={`${d.label}: ${format(d.value)}${clickable ? '. Open breakdown' : ''}`}
                                    className={`outline-none ${clickable ? 'cursor-pointer' : ''}`}
                                    onMouseEnter={() => setHover(i)}
                                    onMouseLeave={() => setHover(null)}
                                    onFocus={() => setHover(i)}
                                    onBlur={() => setHover(null)}
                                    onClick={() => clickable && onSelect(d)}
                                    onKeyDown={(e) => {
                                        if (clickable && (e.key === 'Enter' || e.key === ' ')) {
                                            e.preventDefault();
                                            onSelect(d);
                                        }
                                    }}
                                >
                                    {/* hit target: the whole column, much bigger than the bar */}
                                    <rect x={x} y={0} width={band} height={innerH} fill={on ? '#eef2ff' : 'transparent'} />
                                    {h > 0 && <path d={topRounded(x + (band - barW) / 2, y(d.value), barW, h)} fill={on ? BAR_ACTIVE : BAR} />}
                                    {i % labelEvery === 0 && (
                                        <text x={x + band / 2} y={innerH + 17} textAnchor="middle" className="fill-slate-500 text-[11px]">
                                            {d.short ?? d.label}
                                        </text>
                                    )}
                                </g>
                            );
                        })}
                    </g>
                </svg>
            )}
            {active && (
                <div
                    className="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs whitespace-nowrap text-white shadow-lg"
                    style={{ left: m.left + hover * band + band / 2, top: m.top + y(active.value) - 6 }}
                >
                    <div className="text-slate-300">{active.label}</div>
                    <div className="font-semibold tabular-nums">{format(active.value)}</div>
                    {onSelect && active.value > 0 && <div className="mt-0.5 text-slate-400">Click for breakdown</div>}
                </div>
            )}
        </div>
    );
}

/**
 * Horizontal ranked bars with the value printed on each row. Rows are buttons.
 * data: [{ key, label, value, hint? }]
 */
export function BarList({ data, format, onSelect, empty = 'Nothing in this period.' }) {
    const max = Math.max(0, ...data.map((d) => d.value));
    if (!data.length) return <div className="py-6 text-center text-sm text-slate-500">{empty}</div>;

    return (
        <ul className="space-y-1">
            {data.map((d) => {
                const Row = onSelect ? 'button' : 'div';
                return (
                    <li key={d.key}>
                        <Row
                            type={onSelect ? 'button' : undefined}
                            onClick={onSelect ? () => onSelect(d) : undefined}
                            title={onSelect ? 'Click to see the orders' : undefined}
                            className={`group grid w-full grid-cols-[minmax(0,10rem)_1fr_auto] items-center gap-3 rounded-lg px-2 py-1.5 text-left text-sm ${onSelect ? 'cursor-pointer hover:bg-indigo-50 focus-visible:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-indigo-500' : ''}`}
                        >
                            <span className="truncate text-slate-700" title={d.label}>
                                {d.label}
                                {d.hint && <span className="block truncate text-xs text-slate-400">{d.hint}</span>}
                            </span>
                            <span className="h-2.5 rounded-full bg-slate-100">
                                <span
                                    className="block h-full rounded-full bg-indigo-500 transition-[width] group-hover:bg-indigo-700"
                                    style={{ width: d.value > 0 && max ? `${Math.max(1.5, (d.value / max) * 100)}%` : 0 }}
                                />
                            </span>
                            <span className="min-w-16 text-right font-medium text-slate-800 tabular-nums">{format(d.value)}</span>
                        </Row>
                    </li>
                );
            })}
        </ul>
    );
}

/** The same numbers as a plain table, for screen readers, copying and exact values. */
export function ChartTable({ data, format, labelHeader, valueHeader }) {
    return (
        <table className="w-full text-sm tabular-nums">
            <thead className="text-left text-slate-500">
                <tr>
                    <th className="py-1.5 pr-4 font-medium">{labelHeader}</th>
                    <th className="py-1.5 text-right font-medium">{valueHeader}</th>
                </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
                {data.map((d) => (
                    <tr key={d.key}>
                        <td className="py-1.5 pr-4">{d.label}</td>
                        <td className="py-1.5 text-right">{format(d.value)}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
