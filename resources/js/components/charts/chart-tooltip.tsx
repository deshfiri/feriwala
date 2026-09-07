export type ChartTooltipEntry = {
    key: string;
    /** The series name. Shown only when more than one series is in play. */
    name: string;
    color: string;
    /** The server's formatted string — never the raw plotted number. */
    value: string;
};

/**
 * The tooltip for every chart in the ERP.
 *
 * It shows the string the server wrote, not the number that was plotted. On a
 * money chart those differ by a currency symbol and a thousands separator, and
 * the raw one is the wrong answer (§36.1).
 *
 * The figures stay in text colour and the series colour rides on a dot beside
 * them, so the values keep their contrast against the popover in both themes
 * rather than inheriting a hue chosen to be readable against a chart fill.
 */
export default function ChartTooltip({
    label,
    entries,
}: {
    label: string;
    entries: ChartTooltipEntry[];
}) {
    if (entries.length === 0) {
        return null;
    }

    return (
        <div className="bg-popover text-popover-foreground border-border min-w-32 rounded-md border px-3 py-2 shadow-md">
            <p className="text-muted-foreground mb-1 text-xs">{label}</p>

            <ul className="space-y-0.5">
                {entries.map((entry) => (
                    <li
                        key={entry.key}
                        className="flex items-center gap-2 text-sm"
                    >
                        <span
                            aria-hidden="true"
                            className="size-2 shrink-0 rounded-full"
                            style={{ backgroundColor: entry.color }}
                        />

                        {entries.length > 1 && (
                            <span className="text-muted-foreground text-xs">
                                {entry.name}
                            </span>
                        )}

                        <span className="ml-auto font-medium tabular-nums">
                            {entry.value}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
