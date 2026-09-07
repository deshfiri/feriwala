---
paths:
    - 'resources/js/components/charts/**'
    - 'resources/js/lib/chart.ts'
---

# Charts

## Charts are plain SVG — there is no charting library

Recharts was removed and must not come back without approval. A chart library brings a
second layout engine and a second palette into a page that already has both, for a line,
a fill and an axis. `resources/js/lib/chart.ts` holds the geometry — scales, path
builders, ring arcs, round-number ticks — and the components draw with it directly.

- Measure the container with `useMeasuredWidth` and draw in real pixels. Scaling a
  `viewBox` instead stretches the type and the stroke along with the plot.
- Render nothing until the first measurement lands; a chart laid out against a width of
  zero flashes before it settles.

## Charts plot numbers and print server-supplied strings

Money on a chart is the easiest place to reintroduce a float. The contract instead:

- A `ChartPoint` carries `value` (the plottable magnitude) and `formatted` (the display
  string, written server-side). Tooltips render `formatted`, never `value`.
- Money y-axes take `ticks: ChartAxisTick[]`, each `{value, label}` with the label already
  formatted. Omit `ticks` only for plain counts, where `niceTicks()` may pick round
  numbers in the browser.
- Series colour is a `tone: 1..5` mapping to `--chart-1..5`, never a hex value, so a chart
  cannot introduce a hue outside the palette. `toneColor()` returns the CSS variable, so
  dark mode re-themes with no re-render.

See `App\Domain\Billing\Queries\AccountSpendSummary` for the server half — it computes
ticks with integer arithmetic on minor units.

## A chart never carries meaning in colour alone

Every series is named in a tooltip or a labelled list beside the drawing, and §33.9
applies here as everywhere. The radial breakdown is the clearest case: the rings only
rank, and the figures are read from the text list next to them.

Gaps are gaps. `plottableRuns()` breaks a path where a point is missing rather than
bridging it — a line drawn straight across missing data invents measurements nobody took.
