# Design: ICD-11 code service

See notes/data-findings.md for the verified facts about the WHO files.

## Import pipeline

1. **ReleaseSource:** build the URL from the `release_url` template in
   config/icd.php; download with `Http::timeout(60)->retry(2)->sink()` to
   `storage/app/icd/{version}/release.zip`, or read `--file` (zip or
   extracted .txt, path inside the container; a missing path fails before
   any download); reuse a cached zip unless `--fresh`; check the zip
   signature (`PK\x03\x04`) and that ZipArchive can open it, deleting
   invalid files downloaded in this run; compute sha256 of the data file.
2. **ReleaseReader:** open the zip with `ZipArchive`, pick the .txt by
   extension excluding readme.txt, read it with `getStream()` without
   extracting; strip BOM, split on every LF (records end in CRLF, while the
   unquoted line breaks inside CodingNote are bare LF), reassemble records
   until the field count matches the header, parse each record as quoted
   TSV, yield from a generator. Line breaks inside a field are kept
   byte-exact. A record with too many fields, a record still short of the
   field count at a CRLF (only bare LF occurs inside a record), or end of
   file inside a record, fails with the physical line number where the
   record starts (every LF counts as a line; the header is line 1).
3. **SchemaDetector:** map header names case-insensitively with aliases
   (see Columns below);
   ignore unknown columns and record them in the map; treat the `Version:`
   cell as metadata, map it to `YYYY-MM` by year and month and check it
   against the requested version; CodingNote and Parent optional; store the
   map on the release; fail on a missing required column or a missing
   `Version:` cell.
4. **RowNormalizer:** strip the `- ` depth prefix and surrounding whitespace
   from titles and codes, empty to null, `True`/`False` to booleans. Titles
   and codes are otherwise unmodified.
5. **ChapterFilter:** keep chapters from config/icd.php.
6. **HierarchyBuilder:** depth from the title prefix (not DepthInKind); a
   stack gives each row its parent URI; cross-check against the block ids
   in Grouping1-5 and against Parent when present. Any mismatch, or a depth
   more than one level below the previous row, fails the import.
7. **ReleaseImporter:** one transaction: delete this release's rows, bulk
   insert in chunks, build search vectors, run the diff, store changes,
   commit.
8. **Command output:** summary table, `--details` for the full list.

## Columns

| Column | Status | Aliases |
| --- | --- | --- |
| Linearization URI | required | Linearization (release) URI |
| Foundation URI | required | |
| Code | required | |
| BlockId | required | |
| Title | required | |
| ClassKind | required | |
| IsResidual | required | |
| ChapterNo | required | |
| isLeaf | required | IsLeaf |
| Primary tabulation | required | Primary Tabulation |
| Grouping1 to Grouping5 | required | |
| CodingNote | optional | |
| Parent | optional | |
| DepthInKind | ignored | |
| BrowserLink | ignored | |

Matching is case-insensitive, so aliases differing only in case are listed
for clarity. Any other column is ignored and recorded in `column_map`.

## Release files

Both releases ship with identical file names, so a file name says nothing
about the version. The header stamp is the only reliable marker. Downloads
are stored per version, gitignored, and reused. Only the .txt is read. An
unknown version (HTTP 404), a zip without a .txt, or a stamp mismatch stops
the import and writes nothing. A cached zip with the wrong stamp is not
deleted; the message tells the user to remove it or use `--fresh`. The
version argument must match `YYYY-MM`.

## Diff

Runs after the import, inside the same transaction, on the two snapshots in
the database. `ReleaseDiffer::diff(array $old, array $new): ChangeSet` is a
pure function over maps keyed by linearization URI.

- Parents compared by `parent_uri`, because `parent_id` is a per-release
  surrogate key.
- "Previous" is the highest imported version below the current one.
- Change types: `added`, `removed`, `recoded`, `moved`, `code_reused`,
  `retitled` (substantive), `retitled_cosmetic` (equal after lowercasing,
  replacing every non-letter, non-digit with a space and collapsing
  whitespace).
  One URI can produce several records, one per type.
- `code_reused`: the same code points to different URIs in the two
  releases. An entity leaving the configured chapters counts as removed.
- A changed BlockId under the same URI is not reported; the data findings
  show no such changes between 2025-01 and 2026-01.
- Importing a release between two existing ones recomputes the diff of the
  next newer release.
- `--force` under the same version prints the diff against the stored
  snapshot and does not store it.

## Search pipeline

1. **QueryClassifier:** normalize (lowercase, trim, hyphens to spaces outside
   codes); a regex decides code or code prefix vs free text. Code queries
   are upper-cased for the code tier; all codes in the files are upper
   case.
2. **Strategies:** exact/prefix code; full-text over a weighted vector (own
   title weight A, ancestor titles weight C), all words required, prefix
   match on the last word; if fewer results than the limit, a fallback where
   any word may match, ranked by how many match; `pg_trgm` word similarity
   above the fixed `search.trigram_threshold` in config/icd.php for typos.
3. **Tiers:** code > full-text (all words) > full-text (some words) > trigram.
4. **Order within a tier:** the code tier puts an exact match first, then
   code order. In the full-text and fuzzy tiers, equal scores are broken by
   primary coding before supplementary, specific before residual, shorter
   title, then code; this comparator is a pure function.
5. **Deduplicate by entity**, keep the best tier, apply limit.
6. **Explain:** `matched_via` is `code`, `title`, `partial` or `fuzzy`.
7. **Release:** the latest imported release unless `release` is given; an
   unknown release gives 404, no imported release gives 503 ("No ICD
   release imported").
8. **Query safety:** user input reaches tsquery only through
   `websearch_to_tsquery`/bound parameters, so operator characters never
   cause a database error.

## Data model

| Table | Key columns | Notes |
| --- | --- | --- |
| `releases` | version, source_url, sha256, column_map (json), version_stamp, imported_at | One row per imported release |
| `icd_entities` | release_id, linearization_uri, foundation_uri (nullable), code (nullable), block_id (nullable), title, class_kind, chapter, depth, parent_id, parent_uri, is_leaf, is_residual, is_primary_tabulation (null for chapters and blocks), coding_note (nullable), search_vector | Unique (release_id, linearization_uri) |
| `release_changes` | release_id, previous_release_id, change_type, linearization_uri, before (json), after (json) | |

Indexes: GIN on `search_vector`, GIN trigram on `title`, btree on
(release_id, code) and (release_id, linearization_uri). `pg_trgm` created in
a migration.

## Code layout

```
app/
  Console/Commands/{ImportIcdRelease,SearchIcd}.php
  Icd/Import/{ReleaseSource,ReleaseReader,SchemaDetector,RowNormalizer,HierarchyBuilder,ReleaseImporter}.php
  Icd/Diff/{ReleaseDiffer,ChangeSet,EntitySnapshot}.php
  Icd/Search/{QueryClassifier,CodeSearch}.php
  Http/Controllers/Api/{Search,Code}Controller.php
  Http/Requests/SearchRequest.php
  Http/Resources/IcdEntityResource.php
config/icd.php
routes/api.php
tests/Fixtures/icd/
```

## Test fixtures

Small files in `tests/Fixtures/icd/`. Search fixtures may contain a
handful of verbatim WHO rows: titles, URIs, flags and coding notes copied
exactly from the release, never modified. Synthetic rows use `ZZ` codes,
`ZZ` block ids, `Synthetic` in the title and URIs under
`http://id.who.int/icd/release/11/mms/synthetic/`, so they can never be
mistaken for WHO content. Never whole release files, never real rows with
altered content.

`search.txt` (2026-01 format, stamp `Version:2026 Jan 17 - 05:30 UTC`):

| Row | Chapter | Kind | Primary tabulation | Used by |
| --- | --- | --- | --- | --- |
| Every 6A7 category, including 6A70, its children and 6A71 | 06 | category | as in 2026-01 | code lookup, depr, depresion, depressive |
| 6D80.0, 6D80.1, 6D80.Z (6D80.Z residual) | 06 | category | False | alzheimers onset, coding note |
| ME84.2 Low back pain, ME84.20 Lumbago with sciatica | 21 | category | True | back pain, leaf_only, chapter, code endpoint |
| FA33.0 Cystic meniscus, FA33.1 Discoid meniscus | 15 | category | as in 2026-01 | knee meniscus |
| BlockL1-ZZ0 "Synthetic back pain conditions" | 21 | block | (none) | include_blocks |
| ZZ01 "Synthetic supplementary back pain", leaf under BlockL1-ZZ0 | 21 | category | False | primary_only |
| ZZ02 "Synthetic shoulder pain", leaf under BlockL1-ZZ1 "Synthetic joint conditions" | 15 | category | True | chapter |

Every real row comes with all its ancestor chapters, blocks and categories
from 2026-01. 6D80.0 carries its real 2026-01 coding note ("This category
should never be used in primary tabulation. ..."), copied verbatim when the
fixture is built; 6D80.1 and 6D80.Z have none, as in 2026-01.

`search-2025.txt`: the same rows in the 2025-01 format (17 columns, no
CodingNote or Parent), stamp `Version:2025 Jan 24 - 22:30 UTC`.

Diff fixtures, one chapter each, in the 2026-01 format:

- `diff-2025-01.txt` (stamp `Version:2025 Jan 24 - 22:30 UTC`) and
  `diff-2026-01.txt` (stamp `Version:2026 Jan 17 - 05:30 UTC`): one entity
  per change type (added, removed, recoded, moved, code_reused, retitled,
  retitled_cosmetic); the added entity takes the removed entity's code.
- `diff-2027-01.txt` (stamp `Version:2027 Jan 15 - 00:00 UTC`): identical
  to `diff-2026-01.txt` except for one substantive retitle.

## Local environment

Own Docker Compose: PostgreSQL 17 with healthcheck, a PHP 8.5 container with
the `zip` and `pdo_pgsql` extensions that runs composer install and
migrations, running as the host user (`user: "${UID:-1000}:${GID:-1000}"`) so
files it creates are not owned by root. `.gitattributes` with LF (fixtures
marked `-text`), Composer scripts instead of Make, host ports overridable
(PostgreSQL on 5433 by default), `storage/app/icd/` gitignored.

The container only sees the project folder. A release file on the host is
used by copying it to `storage/app/icd/{version}/release.zip`, where the
import treats it as already downloaded. Tests never use the network: the WHO
download is faked with `Http::fake()` returning a zip built from fixtures.

## Decisions

| Decision | Rejected | Reason |
| --- | --- | --- |
| PostgreSQL with FTS and pg_trgm | SQLite + FTS5, MySQL FULLTEXT, Typesense/Meilisearch | Stemming, weighting and real similarity in one service; a search engine adds index sync and a service with no gain at this size |
| Snapshot per release | Slowly changing dimensions | Trivial diff and per-release queries |
| Identity = linearization URI | Code, foundation URI, title | Codes get reassigned; residuals have no foundation URI |
| Schema detected from headers | Column positions, one parser per release | Releases differ; unknown formats must stop the import |
| Records reassembled by field count | Line-by-line reading | 2026-01 breaks records inside CodingNote |
| Tree from title dashes | DepthInKind, Grouping1-5 | DepthInKind restarts per kind; Grouping lists only blocks (70.8% of parent links are category under category) |
| Diff in PHP on DB snapshots | Diff raw files; SQL full outer join | Normalized data; pure function is unit-testable |
| Ancestor titles in search vector (low weight) | Own title only | "Lumbago with sciatica" sits under "Low back pain" |
| All words, then some words | All words only | An extra word must not empty the result |
| Categories by default, leaf_only as filter | Leaf only by default | Parent categories are valid codes |
| Chapters ranked equally | Chapters 06/15 first | Ranking by chapter is a clinical decision |
| Primary tabulation flag and filter | Ignore it | 212 in-scope codes are supplementary only |
| No synonyms in v1 | Curated lay-English list | Needs clinical validation; next day |
| English only | Swedish synonyms now | Files are English; the brief does not mention Swedish |
| Tests on PostgreSQL | SQLite in memory | SQLite would test a different search |
| Own Docker Compose | Laravel Sail | Same on every OS; only Docker required |

## Licence

- Attribution in the README and in `meta.attribution` of every API response.
- WHO titles and codes are stored and served unmodified apart from
  technical normalisation: stripping the BOM, the depth prefix and
  surrounding whitespace. Filtering to four chapters is a selection.
- Any future synonyms stay separate data and are never merged into titles.
- No own translations; Swedish must come from an official translation.
