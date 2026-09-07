import { useState } from 'react';
import ChartTooltip from '@/components/charts/chart-tooltip';
import { useMeasuredWidth } from '@/hooks/use-measured-width';
import {
    areaPath,
    buildScales,
    hasPlottableData,
    linePath,
    niceTicks,
    plottableRuns,
    toneColor,
} from '@/lib/chart';
import type { ChartAxisTick, ChartSeries } from '@/types';

/**
 * A value over time (§33.3).
 *
 * Drawn as plain SVG. A charting library would bring a second layout engine and
 * a second palette into a page that already has both, for a line, a fill and an
 * axis — and this one is scaled by the same tokens as everything around it, so
 * it re-themes in dark mode without being told.
 *
 * The fill is flat, not a gradient. §33.1 rules gradients out, and a fade adds
 * nothing here anyway — the line carries the shape and the fill only says which
 * side of it is "under".
 *
 * `ticks` come from the server for money axes, already carrying their labels, so
 * an amount is never assembled in the browser (§36.1). Without them the axis
 * picks round numbers itself, which is right for counts.
 */
export default function AreaTrend({
    series,
    ticks,
    height = 240,
    emptyMessage,
}: {
    series: ChartSeries[];
    ticks?: ChartAxisTick[];
    height?: number;
    emptyMessage: string;
}) {
    const { ref, width } = useMeasuredWidth<HTMLDivElement>();
    const [hovered, setHovered] = useState<number | null>(null);

    if (!hasPlottableData(series)) {
        return (
            <p
                className="text-muted-foreground flex items-center justify-center text-center text-sm"
                style={{ height }}
            >
                {emptyMessage}
            </p>
        );
    }

    const length = Math.max(...series.map((one) => one.points.length));

    const frame = {
        width,
        height,
        left: ticks ? 68 : 44,
        right: 8,
        top: 8,
        bottom: 26,
    };

    const scales = buildScales(series, frame, length);

    const axisTicks =
        ticks ??
        niceTicks(scales.min, scales.max).map((value) => ({
            value,
            label: String(value),
        }));

    // Every series shares the x axis, so the labels come from whichever one runs
    // the full width of it.
    const labels =
        series.find((one) => one.points.length === length)?.points ?? [];

    const plotWidth = Math.max(0, frame.width - frame.left - frame.right);
    const slot = length > 1 ? plotWidth / (length - 1) : plotWidth;

    /** Which point the pointer is nearest, or null once it leaves. */
    const trackPointer = (event: React.PointerEvent<SVGSVGElement>) => {
        const bounds = event.currentTarget.getBoundingClientRect();
        const offset = event.clientX - bounds.left - frame.left;

        const index = Math.round(offset / (slot || 1));

        setHovered(index >= 0 && index < length ? index : null);
    };

    const active = hovered === null ? null : labels[hovered];

    return (
        <div ref={ref} className="relative" style={{ height }}>
            {width > 0 && (
                <svg
                    width={width}
                    height={height}
                    role="img"
                    aria-label={series.map((one) => one.label).join(', ')}
                    onPointerMove={trackPointer}
                    onPointerLeave={() => setHovered(null)}
                >
                    {/* Horizontal rules only. Vertical ones would fence off each
                        month without helping anyone read a value off the axis. */}
                    {axisTicks.map((tick) => (
                        <g key={`grid-${tick.value}`}>
                            <line
                                x1={frame.left}
                                x2={width - frame.right}
                                y1={scales.y(tick.value)}
                                y2={scales.y(tick.value)}
                                stroke="var(--color-border)"
                                strokeDasharray="3 3"
                            />
                            <text
                                x={frame.left - 8}
                                y={scales.y(tick.value)}
                                textAnchor="end"
                                dominantBaseline="middle"
                                className="fill-muted-foreground text-[11px]"
                            >
                                {tick.label}
                            </text>
                        </g>
                    ))}

                    {labels.map((point, index) => {
                        // Thin out labels rather than let them collide; the
                        // tooltip names the exact month on hover regardless.
                        const every = Math.ceil(
                            length / Math.max(1, plotWidth / 64),
                        );

                        if (index % every !== 0 && index !== length - 1) {
                            return null;
                        }

                        return (
                            <text
                                key={`label-${point.label}-${index}`}
                                x={scales.x(index)}
                                y={height - 8}
                                // The end labels anchor inward. Centred, the
                                // first and last sit half off the plot and get
                                // clipped by the card.
                                textAnchor={
                                    index === 0
                                        ? 'start'
                                        : index === length - 1
                                          ? 'end'
                                          : 'middle'
                                }
                                className="fill-muted-foreground text-[11px]"
                            >
                                {point.label}
                            </text>
                        );
                    })}

                    {series.map((one) =>
                        plottableRuns(one, length).map((run, position) => (
                            <g key={`${one.key}-${position}`}>
                                <path
                                    d={areaPath(run, scales)}
                                    fill={toneColor(one.tone)}
                                    fillOpacity={0.12}
                                />
                                <path
                                    d={linePath(run, scales)}
                                    fill="none"
                                    stroke={toneColor(one.tone)}
                                    strokeWidth={2}
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                />
                            </g>
                        )),
                    )}

                    {hovered !== null && (
                        <g>
                            <line
                                x1={scales.x(hovered)}
                                x2={scales.x(hovered)}
                                y1={frame.top}
                                y2={height - frame.bottom}
                                stroke="var(--color-border)"
                            />

                            {series.map((one) => {
                                const point = one.points[hovered];

                                if (point === undefined) {
                                    return null;
                                }

                                return (
                                    <circle
                                        key={`dot-${one.key}`}
                                        cx={scales.x(hovered)}
                                        cy={scales.y(point.value)}
                                        r={4}
                                        fill={toneColor(one.tone)}
                                        // A ring in the surface colour keeps the
                                        // dot legible where it crosses its line.
                                        stroke="var(--color-card)"
                                        strokeWidth={2}
                                    />
                                );
                            })}
                        </g>
                    )}
                </svg>
            )}

            {hovered !== null && active && (
                <div
                    className="pointer-events-none absolute top-0 z-10 -translate-x-1/2"
                    style={{
                        // Kept inside the plot, so a tooltip on the last month
                        // does not hang off the edge of the card.
                        left: Math.min(
                            Math.max(scales.x(hovered), frame.left + 60),
                            width - 60,
                        ),
                    }}
                >
                    <ChartTooltip
                        label={active.label}
                        entries={series.flatMap((one) => {
                            const point = one.points[hovered];

                            return point === undefined
                                ? []
                                : [
                                      {
                                          key: one.key,
                                          name: one.label,
                                          color: toneColor(one.tone),
                                          value: point.formatted,
                                      },
                                  ];
                        })}
                    />
                </div>
            )}
        </div>
    );
}
