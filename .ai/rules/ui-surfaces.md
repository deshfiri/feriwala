---
paths:
    - 'resources/js/pages/**'
    - 'resources/js/layouts/**'
    - 'resources/js/components/**'
    - 'resources/css/app.css'
---

# Page surfaces and layout

Reach for these before writing a wrapper `div`. Three idioms for "a card" had already
grown once — one with a background at 8px, one bordered with no background at all, one at
16px — and on an off-white canvas the backgroundless ones were not cards, they were
outlines with the page showing through.

## `PageContainer` owns the gutters

Every authenticated screen opens with it. It sets 16px on a phone and 24px from `sm` up,
plus the vertical rhythm between sections. Do not hand-roll `space-y-6 p-4`: the gutter
then becomes whatever the last person typed, and a screen that disagrees with its
neighbour by four pixels is the kind of thing nobody reports and everybody feels.

`width="narrow"` for forms and detail panels, the default `full` for tables and
dashboards. A 1600px-wide text input is harder to fill in than a narrow one, not easier.

## `SectionCard` is the panel

Title, optional description, optional actions and footer, on the standard card surface.
Use it for every grouped block on a form or detail screen rather than writing
`bg-card border-border rounded-xl border p-5 shadow-sm` again.

- `headingLevel` is a prop because a section is not always second-level; nesting an `h3`
  under an `h2` is what lets a screen-reader user move through the page by structure.
- `tone="destructive"` marks the whole panel, not the button, so someone scrolling past
  can tell this is the dangerous end of the page before reading a word.

## `StatCard` / `StatCardGrid` for figures

A KPI row is a description list and is marked up as one, so it reads as "Orders today,
42" rather than two unrelated numbers. Pass an already-formatted value — a percentage
computed in the browser is one the server cannot vouch for (§36.1). `change` spells its
direction out in the text; the colour is never the only carrier (§33.9).

## Radius is a token, not a choice

`--radius` (8px) drives controls. Surfaces are rounder: `rounded-xl` is 12px and
`rounded-2xl` is 16px, both derived from it in `app.css`. Never pick a radius per screen.
Alert boxes and dialog fieldsets stay at `rounded-lg` on purpose — they sit _on_ a
surface, they are not one. `Notice` follows the same rule through its `inset` prop.

## Reach for the shared control, not its class string

- `DetailList` / `DetailItem` for label-and-value pairs. Not a local `Detail` helper.
- `Notice` for a shortfall, a reconciliation flag, a returned round — anything stated
  in words in a tinted box.
- `NativeSelect` and `Textarea` for native form controls. They match `Input`; a
  hand-typed `border-input … rounded-lg px-3 py-2` does not.
- Status hues (`text-warning`, `text-danger`…) are dark enough to be text on their
  own `-subtle` ground in both themes. Do not reach for a Tailwind palette colour.

## The vendored primitives are skinned by `data-slot`, not edited

`components/ui` is never hand-edited. The few defaults that do not belong to the
palette — dialog overlay, dialog surface and corner — are corrected at the bottom of
`app.css` against the `data-slot` each primitive stamps.

## Anything painted before hydration must track its token

`resources/views/app.blade.php` paints the canvas colour inline, before the stylesheet
loads. Those two values mirror `--background` for each theme; if you change the token,
change them. They had drifted once, and every page load opened with a flash of a colour
the page was about to stop being. `tests/Feature/Ui/AppearanceShellTest.php` fails if they
drift again.

## Appearance is exactly Light, Dark and System

No Dim, no High Contrast, no brand themes — a fourth palette is a whole second set of
surfaces to keep in step, and the one that gets forgotten is always the one nobody is
looking at. "System" is not a third palette; it is a standing instruction to follow
`prefers-color-scheme`, including when the device flips at sunset. The preference is
stored in `localStorage` _and_ a cookie, and the cookie is exempt from encryption in
`bootstrap/app.php` because the hook writes it from JavaScript.

## Page breadcrumb titles are hard-coded English — fix app-wide on the UI-upgrade branch

Admin pages set `Page.layout = { breadcrumbs: [{ title: 'Stock', ... }] }` with literal English strings (15+ pages: inventory, KYC, payments, wallets, activations), so the header breadcrumb stays English in Bangla. Known app-wide debt, recorded 2026-09-14 for `ui/ultra-modern-saas`: translate breadcrumbs in one pass there (e.g. resolve a lang key in the layout). Do not patch it piecemeal inside feature tasks; new pages follow the existing pattern until that pass lands.

## A derived-once "kind"/"type" value must be React state, not a constant

In a create/edit dialog with a `<select>` that changes which fields render (e.g. `SectionDialog` picking content fields by `kind`), never compute that value once as `const kind = x ?? y` and read the `<select>` as uncontrolled (`defaultValue`). The dropdown will visually show the new choice but nothing driven by `kind` will update, and the mismatch only surfaces when you actually submit and compare. Make it `useState`, seed it from props via `useEffect`, and drive the `<select>` with `value`/`onChange`. Caught by browser-testing `section-dialog.tsx` (Stage 7): selecting a different section kind never changed the content fields shown, though the underlying native select still posted the right value.
