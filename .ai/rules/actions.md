---
paths:
  - 'resources/js/routes/**, resources/js/actions/**'
---

# Actions

## Always pass --with-form to wayfinder:generate
`php artisan wayfinder:generate` without `--with-form` omits the `.form` helper that dozens of existing pages call on route/action objects (e.g. `login().form`, `register().form`). The Vite plugin (`@laravel/vite-plugin-wayfinder`) includes this flag automatically during `npm run dev`/`composer run dev`, so it's easy to miss when regenerating manually. A bare manual regenerate silently breaks `tsc --noEmit` across ~40 unrelated files with `Property 'form' does not exist` errors that look like a wayfinder/version regression but are just the missing flag. Always run `php artisan wayfinder:generate --with-form` (or just use `npm run dev`) instead of the bare command.
