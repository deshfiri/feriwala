import type { ChartPoint, ChartSeries } from '@/types';

/**
 * The palette token a series paints with.
 *
 * A CSS variable rather than a resolved colour, so a chart re-themes with the
 * rest of the page when the user switches to dark — no re-render, no colour
 * arithmetic, nothing to keep in step.
 */
export function toneColor(tone: ChartSeries['tone'] = 1): string {
    return `var(--chart-${tone})`;
}

/**
 * Whether there is anything worth drawing.
 *
 * A series of all zeroes is data — a month with no payments is a real answer,
 * and its flat line says so. No points at all is not, and belongs in an empty
 * state instead of an axis with nothing under it.
 */
export function hasPlottableData(series: ChartSeries[]): boolean {
    return series.some((one) => one.points.length > 0);
}

/**
 * The plotting area inside a chart, once the axes have taken their room.
 */
export type ChartFrame = {
    width: number;
    height: number;
    left: number;
    right: number;
    top: number;
    bottom: number;
};

export type ChartScales = {
    /** Point index to horizontal pixel. */
    x: (index: number) => number;
    /** Value to vertical pixel. */
    y: (value: number) => number;
    /** Where zero sits — the line an area fills down to. */
    baseline: number;
    min: number;
    max: number;
};

/**
 * Build the two scales a cartesian chart needs.
 *
 * The domain always includes zero. A spend chart whose axis starts at its own
 * minimum turns a rise from 39,000 to 40,000 into a cliff — the shape has to be
 * read against nothing, which is how a chart lies without a single wrong number
 * in it.
 *
 * A flat series gets a padded domain rather than a zero-height one, so it draws
 * as a line across the middle instead of collapsing onto the axis.
 */
export function buildScales(
    series: ChartSeries[],
    frame: ChartFrame,
    length: number,
): ChartScales {
    const values = series.flatMap((one) =>
        one.points.map((point) => point.value),
    );

    const min = Math.min(0, ...values);
    const rawMax = Math.max(0, ...values);
    const max = rawMax === min ? min + 1 : rawMax;

    const plotWidth = Math.max(0, frame.width - frame.left - frame.right);
    const plotHeight = Math.max(0, frame.height - frame.top - frame.bottom);

    // A single point sits in the middle rather than hard against the y axis.
    const step = length > 1 ? plotWidth / (length - 1) : 0;

    return {
        x: (index) =>
            length > 1 ? frame.left + index * step : frame.left + plotWidth / 2,
        y: (value) =>
            frame.top + plotHeight - ((value - min) / (max - min)) * plotHeight,
        baseline:
            frame.top + plotHeight - ((0 - min) / (max - min)) * plotHeight,
        min,
        max,
    };
}

/**
 * The runs of consecutive points a series actually has.
 *
 * A series shorter than the longest simply stops, and a gap in the middle is a
 * month with no reading. Neither is bridged: a line drawn straight across
 * missing data invents measurements that were never taken.
 */
export function plottableRuns(
    series: ChartSeries,
    length: number,
): { index: number; point: ChartPoint }[][] {
    const runs: { index: number; point: ChartPoint }[][] = [];
    let run: { index: number; point: ChartPoint }[] = [];

    for (let index = 0; index < length; index += 1) {
        const point = series.points[index];

        if (point === undefined) {
            if (run.length > 0) {
                runs.push(run);
                run = [];
            }

            continue;
        }

        run.push({ index, point });
    }

    if (run.length > 0) {
        runs.push(run);
    }

    return runs;
}

/**
 * `d` for a run of points, as a plain polyline.
 *
 * Straight segments, not a spline. A smoothed curve through monthly totals
 * invents values between the months and can dip below zero on the way, which for
 * money is not a stylistic choice.
 */
export function linePath(
    run: { index: number; point: ChartPoint }[],
    scales: ChartScales,
): string {
    return run
        .map(
            ({ index, point }, position) =>
                `${position === 0 ? 'M' : 'L'}${scales.x(index).toFixed(2)},${scales.y(point.value).toFixed(2)}`,
        )
        .join(' ');
}

/**
 * `d` for the fill under a run, closed down to the zero line.
 */
export function areaPath(
    run: { index: number; point: ChartPoint }[],
    scales: ChartScales,
): string {
    if (run.length === 0) {
        return '';
    }

    const first = run[0];
    const last = run[run.length - 1];
    const baseline = scales.baseline.toFixed(2);

    return [
        linePath(run, scales),
        `L${scales.x(last.index).toFixed(2)},${baseline}`,
        `L${scales.x(first.index).toFixed(2)},${baseline}`,
        'Z',
    ].join(' ');
}

/**
 * Round tick values covering a domain, when the server has not supplied its own.
 *
 * Money axes arrive with their labels already written (§36.1); this is for plain
 * counts, where a browser may safely decide that the axis runs 0, 5, 10, 15.
 */
export function niceTicks(min: number, max: number, count = 4): number[] {
    const span = max - min;

    if (span <= 0) {
        return [min];
    }

    const rough = span / count;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step =
        [1, 2, 2.5, 5, 10].find((factor) => factor * magnitude >= rough) ?? 10;
    const size = step * magnitude;

    const ticks: number[] = [];
    for (
        let value = Math.ceil(min / size) * size;
        value <= max;
        value += size
    ) {
        ticks.push(Number(value.toFixed(10)));
    }

    return ticks;
}

/**
 * `d` for a ring segment drawn clockwise from twelve o'clock.
 *
 * Split at the halfway mark so the arc flag never has to be reasoned about: two
 * sweeps of at most 180° each are always unambiguous, including the full circle
 * that a single-slice breakdown produces.
 */
export function ringPath(
    centre: number,
    radius: number,
    fraction: number,
): string {
    const clamped = Math.max(0, Math.min(1, fraction));

    if (clamped === 0) {
        return '';
    }

    const pointAt = (turn: number) => {
        const angle = turn * 2 * Math.PI - Math.PI / 2;

        return [
            (centre + radius * Math.cos(angle)).toFixed(2),
            (centre + radius * Math.sin(angle)).toFixed(2),
        ];
    };

    const [startX, startY] = pointAt(0);
    const [midX, midY] = pointAt(Math.min(clamped, 0.5));
    const first = `M${startX},${startY} A${radius},${radius} 0 0 1 ${midX},${midY}`;

    if (clamped <= 0.5) {
        return first;
    }

    // Stop a hair short of a closed circle, or the end lands on the start and
    // the arc renders as nothing at all.
    const [endX, endY] = pointAt(Math.min(clamped, 0.9999));

    return `${first} A${radius},${radius} 0 0 1 ${endX},${endY}`;
}
