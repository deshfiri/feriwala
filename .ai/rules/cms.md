---
paths:
  - 'resources/js/pages/admin/cms/**'
---

# Cms

## Section field validation errors are keyed bare, not by the content[...] form name
`SectionContentValidator` validates the unwrapped `content` array directly, so its error keys are bare dot-paths (`heading.en`, `primary_cta.label.en`) — never prefixed with `content[...]`, since that wrapper is only a form-field naming convention, not part of what the inner validator ever sees. A field's error lookup must convert its form `name` (`content[primary_cta][label]`) to the bare key (`primary_cta.label`) before appending `.en`/`.bn`/`.href` — see `errorKey()` in `section-fields.tsx`. Found because Stage 7's original fields computed `${name}.en` directly, which never matched and silently showed no inline error for a rejected section save.
