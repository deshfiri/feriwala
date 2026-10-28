---
paths:
  - 'lang/**'
---

# Lang

## Enum labels must be translated, and CaseTranslationsTest guards it
Case enums use HasTranslatedLabel (lang status.<group>.<value>); never hard-code English in label(). Every backend refusal code needs cases.errors.<code> in en and bn. tests/Feature/Cases/CaseTranslationsTest.php fails on a missing Bangla line.
