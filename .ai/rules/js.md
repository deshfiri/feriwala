---
paths:
    - 'resources/js/**'
---

# Js

## Escape a literal hyphen in an HTML pattern attribute's character class

`pattern="^[a-z0-9-]+$"` (and even `^[-a-z0-9]+$`) throws "Uncaught SyntaxError: Invalid regular expression" in current Chrome, which compiles the `pattern` attribute under Unicode Sets (`v`) mode. The uncaught exception can abort in-flight form handling, not just skip validation. Either escape it (`pattern="^[a-z0-9\\-]+$"` — note the double backslash needed in a JS string so a literal `\-` reaches the DOM) or, simpler, drop the client-side `pattern` entirely and rely on server validation + `InputError` for anything already enforced by a FormRequest rule (`regex:/^[a-z0-9-]+$/`). Found in `section-dialog.tsx`'s section-key field (Stage 7).

## Pass explicit paths to vp check --fix
`npx vp check --fix <path> [<path>...]` works and only touches the files you name. Use it instead of a bare `npx vp check --fix`, which reformats the whole repo including `.md` files you did not touch and leaves unrelated churn in `git status`.

Bare `npx vp check` (no `--fix`) is still the right way to see whether anything is wrong.
