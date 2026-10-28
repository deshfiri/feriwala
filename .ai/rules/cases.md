---
paths:
  - 'app/Domain/Cases/**'
---

# Cases

## Cross-links go through CaseLinks; evidence text columns are erasable
Every link between an Order, case, Exchange Order and neighbouring exchanges is built by CaseLinks (url null when the reader may not open it) - never hand-build these URLs in a payload. case_evidence is immutable by trigger except one narrow one-way guard (migration 2026_11_18): display_name -> '[redacted]', note -> null/'[redacted]', and the file detached once with file_erased_at. Nothing else may be unlocked for erasure.
