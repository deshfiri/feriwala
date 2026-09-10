---
paths:
    - 'tests/**'
---

# Tests

## Never rebuild frontend assets while the suite is running

Every Inertia response test renders the page shell, which resolves the entry through Vite: `public/hot` when the dev server is up, `public/build/manifest.json` otherwise. Deleting `public/hot` or running `npm run build` mid-run makes every one of those tests fail with `ViteException: Unable to locate file in Vite manifest` and a 500 — 44 at once, spread across modules nobody touched, which reads exactly like a real regression.

If you need the app served without the Vite dev server (a curl-driven browser check, say), build **before** starting the suite, not during it. A new page added since the last build is also missing from the manifest, so build after adding pages if the dev server is not running.
