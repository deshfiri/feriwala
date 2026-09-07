import { ringPath, toneColor } from '@/lib/chart';
import type { ChartSlice } from '@/types';

/**
 * How a total divides between a handful of parts (§33.3).
 *
 * The rings are the summary; the list beneath them is the answer. Reading a
 * value off a radial is guesswork at the best of times, and impossible for
 * anyone who cannot separate the colours — so every slice states its label and
 * its figure in text, and the rings only rank them at a glance (§33.9).
 *
 * Each ring is that slice's share of the whole rather than its share of the
 * largest slice, so a ring that looks half full is half the spend. Ranking
 * against the biggest slice would make the runner-up look enormous whenever the
 * leader was small.
 *
 * Deliberately capped in practice: past five or six rings they stop ranking
 * anything and a table does this job better.
 */
export default function RadialBreakdown({
    slices,
    size = 180,
    emptyMessage,
}: {
    slices: ChartSlice[];
    size?: number;
    emptyMessage: string;
}) {
    const plottable = slices.filter((slice) => slice.value > 0);

    if (plottable.length === 0) {
        return (
            <p
                className="text-muted-foreground flex items-center justify-center text-center text-sm"
                style={{ minHeight: size }}
            >
                {emptyMessage}
            </p>
        );
    }

    const total = plottable.reduce((sum, slice) => sum + slice.value, 0);

    const centre = size / 2;
    const thickness = Math.max(6, size / (plottable.length * 3.2));
    const gap = 4;

    const toneFor = (slice: ChartSlice, index: number) =>
        toneColor(slice.tone ?? (((index % 5) + 1) as ChartSlice['tone']));

    return (
        <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-center sm:gap-6">
            {/*
             * Hidden from assistive technology on purpose: the list beside it
             * carries the same information as text, and announcing the rings too
             * would read every figure twice.
             */}
            <svg
                aria-hidden="true"
                width={size}
                height={size}
                className="shrink-0"
            >
                {plottable.map((slice, index) => {
                    const radius =
                        centre - thickness / 2 - index * (thickness + gap);

                    if (radius <= 0) {
                        return null;
                    }

                    return (
                        <g key={slice.key}>
                            {/* The unfilled remainder, so a small share reads as
                                a short arc on a full ring rather than a stray
                                mark floating in space. */}
                            <circle
                                cx={centre}
                                cy={centre}
                                r={radius}
                                fill="none"
                                stroke="var(--color-muted)"
                                strokeWidth={thickness}
                            />

                            <path
                                d={ringPath(
                                    centre,
                                    radius,
                                    slice.value / total,
                                )}
                                fill="none"
                                stroke={toneFor(slice, index)}
                                strokeWidth={thickness}
                                strokeLinecap="round"
                            />
                        </g>
                    );
                })}
            </svg>

            <ul className="min-w-0 flex-1 space-y-2">
                {plottable.map((slice, index) => (
                    <li
                        key={slice.key}
                        className="flex items-center gap-2 text-sm"
                    >
                        <span
                            aria-hidden="true"
                            className="size-2 shrink-0 rounded-full"
                            style={{ backgroundColor: toneFor(slice, index) }}
                        />
                        <span className="text-muted-foreground min-w-0 flex-1 truncate">
                            {slice.label}
                        </span>
                        <span className="shrink-0 font-medium tabular-nums">
                            {slice.formatted}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
