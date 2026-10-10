# Data findings: WHO ICD-11 MMS SimpleTabulation (English)

Inspected 2026-10-10. Both releases were read directly from the release
files; numbers below come from the files, not the readme.

| Release | Header version stamp | sha256 of .txt |
| --- | --- | --- |
| 2025-01 | `Version:2025 Jan 24 - 22:30 UTC` | `7bc6b566394b8763efccda81f99f5ff0242b8c59d9cd230a90c3f38cba36992c` |
| 2026-01 | `Version:2026 Jan 17 - 05:30 UTC` | `91b6e19048918b0f5cfb5ff56b16262c96e6e301214018f707d84dd6c775e93b` |

## File format

- Tab-separated, UTF-8 **with BOM**, **CRLF** line endings.
- Fields are double-quoted when needed (titles, `BrowserLink`), with `""`
  as an escaped quote.
- `BrowserLink` is an Excel formula, e.g.
  `=hyperlink("https://icd.who.int/browse/latestrelease/mms/en#334423054","browser")`.
  Not needed: links can be built from the URI.
- Booleans are `True` / `False`.
- `ChapterNo` is two digits (`06`), plus `V` and `X` (extension codes).
- `ClassKind` is `chapter`, `block` or `category`. Blocks have a `BlockId`
  and no `Code`.

## The readme does not describe the files

- The two `readme.txt` files are **byte-identical**.
- Header names differ from the readme even within a release:

  | readme | header |
  | --- | --- |
  | `Linearization (release) URI` | `Linearization URI` |
  | `IsLeaf` | `isLeaf` |
  | `Primary Tabulation` | `Primary tabulation` |

- The readme does not mention the columns added in 2026-01.

## Headers

2025-01 (17 data columns):

```
Foundation URI, Linearization URI, Code, BlockId, Title, ClassKind,
DepthInKind, IsResidual, ChapterNo, BrowserLink, isLeaf,
Primary tabulation, Grouping1, Grouping2, Grouping3, Grouping4, Grouping5
```

2026-01 (19 data columns) adds two columns at the end:

```
... Grouping5, CodingNote, Parent
```

In both, the **last header cell is a version stamp** (`Version:...`), not a
data column. Data rows have no value for it.

## Records spanning several lines (2026-01)

`CodingNote` contains **unquoted line breaks**, typically two paragraphs
separated by a blank line. Such a record spans three physical lines:

```
<18 fields ... first paragraph of CodingNote>
<empty line>
<rest of CodingNote>\t<Parent>
```

- 50 records in 2026-01 are affected; 157 in-scope rows have a coding note.
- Reading line by line, or with `fgetcsv` alone, turns each into three
  broken rows.
- Fix: accumulate physical lines until the record has as many fields as the
  header (excluding the version stamp), then parse it as quoted TSV.
  After reassembly, all 37 052 records have exactly 19 fields.

## Identity

- `Linearization URI` is **unique on every row** in both releases. It is the
  identity key across releases.
- **Residual categories** (`.Y` other specified, `.Z` unspecified) have an
  **empty `Foundation URI`**. Their linearization URI is the parent's plus
  `/other` or `/unspecified`, e.g.
  `http://id.who.int/icd/release/11/mms/605267007/unspecified` for 6A00.Z.

## Hierarchy

- The file is ordered depth-first.
- **Absolute depth = number of `- ` prefixes in `Title`**: chapter none,
  top-level block `- `, 6A00 `- - `, 6A00.0 `- - - `. Strip the prefix for
  the display title.
- `DepthInKind` is depth **within the class kind** only (a category with
  DepthInKind 1 can sit under a block). It cannot be used as tree depth.
- 2026-01 `Parent` holds the **parent's foundation URI**; empty only for
  chapters. Parents derived from title depth match it with **zero
  mismatches** in chapters 06, 15, 21, 24. Use it as a cross-check; 2025-01
  has no such column.
- `Grouping1`-`Grouping5` contain **block ids** (e.g. `BlockL1-6A0`), not
  URIs or titles. They match the block ancestors derived from title depth
  with **zero mismatches** in both releases.
- Blocks never list their own `BlockId` in Grouping1-5 (0 of 132 blocks in
  both releases); Grouping holds only ancestor blocks.
- Title depth never jumps by more than one level from one row to the next
  in either release.
- 70.8% of parent links in scope are category under category in both
  releases (2 485 of 3 512 in 2025-01, 2 484 of 3 508 in 2026-01).

## Codes

- All codes in both files are upper case.

## Primary tabulation

- 212 in-scope categories have `Primary tabulation` = `False` in both
  releases.

## Size

| | 2025-01 | 2026-01 |
| --- | --- | --- |
| Records, whole file | 36 782 | 37 052 |
| Chapter X records (2025-01) | 17 458 | |

Rows in scope:

| Chapter | Kind | 2025-01 | 2026-01 |
| --- | --- | --- | --- |
| 06 | chapter / block / category | 1 / 25 / 858 | 1 / 25 / 858 |
| 15 | chapter / block / category | 1 / 15 / 429 | 1 / 15 / 426 |
| 21 | chapter / block / category | 1 / 47 / 1 241 | 1 / 47 / 1 241 |
| 24 | chapter / block / category | 1 / 45 / 852 | 1 / 45 / 851 |
| **Total** | | **3 516** | **3 512** |

## Content diff 2025-01 to 2026-01 (chapters 06, 15, 21, 24)

Matched by linearization URI; parents compared by parent URI; titles
compared after stripping the depth prefix.

| Change | Count |
| --- | --- |
| Added | 0 |
| Removed | 4 |
| Recoded | 0 |
| Moved (new parent) | 0 |
| Code reused for another entity | 0 |
| Left / entered scope | 0 / 0 |
| Retitled | 88 (06: 12, 15: 1, 21: 49, 24: 26) |

Removed:

| Code | Chapter | Residual | Title (2025-01) |
| --- | --- | --- | --- |
| FA37.Z | 15 | yes | Certain joint disorders, not elsewhere classified, unspecified |
| FA5Y | 15 | yes | Other specified arthropathies |
| FA3Z | 15 | yes | Unspecified joint disorders and deformities of limbs |
| QA74 | 24 | no | Unspecified appropriateness of dosing or administration without injury or harm |

None of the removed codes reappear elsewhere in 2026-01.

Retitles:

- 48 only change "and" to "or", e.g. FB51.2 "Fasciitis and fibromatosis"
  to "Fasciitis or fibromatosis".
- 2 only change case: 6C4D.5 and 6C4D.61, "Ketamine" to "ketamine".
- Cosmetic rule: two titles are cosmetic when equal after lowercasing,
  replacing every character that is not a letter or digit with a space,
  and collapsing whitespace. Under this rule the 88 split into 86
  `retitled` (the "and" to "or" changes are substantive) and 2
  `retitled_cosmetic` (the two above).
- 38 are real rewordings, e.g.:
  - QB95.4 "Psychotherapy" to "Contact with health services for care
    involving use of psychotherapy" (the whole QB95 family follows this
    pattern)
  - QB50.1 "Presence of aortocoronary bypass graft" to "Presence of coronary
    artery bypass graft"
  - MC80 "Abnormal blood-pressure reading, without diagnosis" to "...without
    diagnosis of hypertension or hypotension"
  - 6C4F.73 "Impulse control syndrome induced by..." to "Impulse control
    disorder induced by..."
  - MG51.2Z "Enteroccus resistant to unspecified antibiotic" to "Enterococcus
    resistant to unspecified antibiotic"

Title lengths used by the search scenarios: 6D80.1 has 49 characters,
6D80.0 has 50 and 6D80.Z has 63.

Rows used by the search fixtures:

- ME84.2 has `Primary tabulation` = `True` in both releases.
- All 6D80 codes have `Primary tabulation` = `False` in 2026-01. 6D80.0
  has the coding note "This category should never be used in primary
  tabulation. ..."; 6D80.Z is residual and has no note.
- 6A70 has no coding note in 2026-01.

No changes to `isLeaf`, `IsResidual`, `Primary tabulation`, groupings,
`BlockId` or `ClassKind` on entities present in both releases.

## Consequences for the implementation

- Map headers by name, case-insensitively, with aliases; never by position.
- Treat the `Version:` header cell as metadata and check it against the
  requested release.
- Treat `CodingNote` and `Parent` as optional columns.
- Reassemble records by field count before parsing.
- Strip BOM, handle CRLF, parse quoted TSV.
- Depth from the title prefix; parent from a depth stack; cross-check
  against `Parent` when present and against the block ids in Grouping1-5.
- `foundation_uri` is nullable.
- Report cosmetic retitles (case, punctuation) separately from real
  rewordings.
- Fixtures: a 2025-01 header, a 2026-01 header, and a record broken across
  lines by `CodingNote`.
