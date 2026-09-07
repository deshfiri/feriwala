/**
 * Chart data, shaped by the server.
 *
 * Every point carries both a plottable `value` and the `formatted` string a
 * reader sees. The client draws the shape; it never decides what a figure says
 * (§36.1). For money that matters twice over — a chart must not be the one place
 * in the application where an amount is divided by a hundred in JavaScript.
 */
export type ChartPoint = {
    /** The x-axis label, already localized. */
    label: string;
    /** The magnitude to plot. Major units for money, a raw count otherwise. */
    value: number;
    /** What a tooltip shows for this point, formatted server-side. */
    formatted: string;
};

export type ChartSeries = {
    key: string;
    /** Legend and tooltip name, already localized. */
    label: string;
    /**
     * Which token paints it: 1–5 map to `--chart-1` … `--chart-5`. A number
     * rather than a colour, so a series cannot introduce a hue that is not in
     * the palette (§33.1).
     */
    tone?: 1 | 2 | 3 | 4 | 5;
    points: ChartPoint[];
};

/**
 * A y-axis tick with its label already written.
 *
 * The server supplies these for money axes so that "৳12,000" never has to be
 * assembled in the browser. Omit them and the chart falls back to Recharts'
 * own ticks, which is right for plain counts.
 */
export type ChartAxisTick = {
    value: number;
    label: string;
};

/**
 * One slice of a radial breakdown.
 */
export type ChartSlice = {
    key: string;
    label: string;
    value: number;
    formatted: string;
    tone?: 1 | 2 | 3 | 4 | 5;
};
