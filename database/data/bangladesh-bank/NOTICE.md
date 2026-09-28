# Bangladesh bank and branch data — provenance

Bundled here so production never depends on an external attachment or a
re-fetch at runtime.

- **Received as**: three chat attachments (`bangladesh_bank_branches_website.json`,
  `bangladesh_bank_branches_flat.json`, `bangladesh_bank_branches_audit.json`),
  a packaged output of `build_bangladesh_banks.py` run against an uploaded RTF
  bank/branch directory. The base RTF's own ultimate origin is not
  independently verified beyond what the package's own `README_BANK_DATA.md`
  states; the package's `meta.coverage` is honestly self-reported as
  `legacy_source_only_not_complete`, and `not_authoritative_for_payments`
  is `true` on every file.
- **Generated (per `meta.generated_utc`)**: 2026-09-27T16:58:38+00:00
- **External reference** (CC0, not merged into this snapshot —
  `meta.external_reference` is `null`): https://github.com/MdHRShohel/bd-bank-routing
  — a `--refresh`/`--upstream` re-run of `build_bangladesh_banks.py` against
  that source is how a future, more complete snapshot would be produced; this
  importer does not fetch it automatically.
- **Files bundled** (byte-identical to the attachments, renamed only):
  - `bangladesh_bank_branches_flat.json` (4,906,715 bytes, sha256
    `b30ebe4f3ba70c43f1a55216e65a6d79f9e3307c92b17373bdd43ba1e553a2a9`) —
    **canonical import source.** One flat row per branch; confirmed by direct
    diff against `bangladesh_bank_branches_website.json` to carry every field
    the website file has (plus `bank_code`/`bank_name`/`bank_selectable`
    hoisted onto each row instead of the parent bank object) and the exact
    same 8,649 branches (same `routing_number` set, zero set difference
    either direction). All 8,649 rows already have a syntactically valid
    9-digit routing number whose first three digits match their bank's code,
    a non-null district, and no duplicate routing numbers — the packaged
    cleaner already excluded the invalid/ambiguous rows described in the
    audit file below before writing this one.
  - `bangladesh_bank_branches_audit.json` (3,881,236 bytes, sha256
    `fe21f52eb1a8e43deceedb0e7ed3c85ddfb3926405c823335d6ae532fa6d919e`) — the
    `issue_counts` + `issues[]` list of every record the cleaner excluded
    (invalid/missing routing numbers, non-customer routing endpoints,
    rejected contact fields, etc.) and never wrote into the flat/website
    files. Kept for audit trail and surfaced by `bd-banks:import --mode=validate`;
    never used to populate `bd_bank_branches`.
  - `bangladesh_bank_branches_website.json` (5,193,460 bytes, sha256
    `65db676cdba9da405cd4b8c935def136bd411aeee684f0ea59cac0377936e62e`) —
    **canonical for the bank list only.** Its nested `banks[].districts[].branches[]`
    duplicate `_flat.json`'s branch rows exactly (same 8,649 records, same
    fields, confirmed by full diff) and are not read again from here. Its
    top-level `banks[]` entries are read, because three bank-level fields
    (`payable`, `aliases`, `available_in_selector`, `reference`) exist only
    here, never on a flat branch row, and three institutions with zero
    branches (Bangladesh Bank `025`, the Office of the CGA `405`, NCC Bank
    `160`) appear only in this bank list — a branch-count of zero means they
    never appear in `_flat.json`'s branches array at all. The application
    then serves the nested bank → district → branch shape a UI needs from
    `bd_banks`/`bd_bank_branches` directly (`App\Domain\Bank\Queries`)
    instead of keeping this precomputed copy in sync.
- **License**: not stated by the package for the base uploaded directory
  itself. The optional external reference above is CC0. Nothing in this
  bundle asserts a license for the legacy branch contact details it carries
  forward (addresses, phone/fax/email) — treat them as internal reference
  data only, consistent with `not_authoritative_for_payments`.

## Known, reviewed name differences from `bd_locations`

Bank branch records use the district's name as of when the underlying RTF was
compiled. Three of Bangladesh's districts have since been officially
renamed, and `bd_locations` (imported from a different, independently pinned
source — see `database/data/bangladesh-location/NOTICE.md`) kept the older
spelling. These three are the **only** normalized mismatches between the two
sources (verified by comparing all 64 district names from each side,
case-insensitively, with punctuation/underscores stripped — see
`App\Domain\Bank\DistrictAliases`, which is the single place this mapping is
declared and reviewed):

| Bank data (`_flat.json`) | `bd_locations.name_en` |
| --- | --- |
| `BARISHAL`  | `Barisal`   |
| `CUMILLA`   | `Comilla`   |
| `JHALOKATI` | `Jhalakathi` |

Every other district name matches after case/punctuation normalization alone
— no other alias exists, and the importer refuses (reports as unmatched,
never guesses) any district name it cannot resolve through this exact list
or a direct normalized match.
