# Design: ICD-11 code service

See notes/data-findings.md for the verified facts about the WHO files.

## Import pipeline

1. **ReleaseSource:** download with `Http::timeout(60)->retry(2)->sink()` to
   `storage/app/icd/{version}/release.zip`, or read `--file` (zip or
   extracted .txt, path inside the container); reuse a cached zip unless
   `--fresh`; check the zip signature (`PK\x03\x04`) and delete invalid
   downloads; compute sha256 of the data file.
2. **ReleaseReader:** open the zip with `ZipArchive`, pick the .txt by
   extension excluding readme.txt, read it with `getStream()` without
   extracting; strip BOM, split on CRLF, reassemble records until the field
   count matches the header (unquoted line breaks in CodingNote), parse each
   record as quoted TSV, yield from a generator.
3. **SchemaDetector:** map header names case-insensitively with aliases;
   treat the `Version:` cell as metadata and check it against the requested
   version; CodingNote and Parent optional; store the map on the release;
   fail loudly on missing required columns.
4. **RowNormalizer:** strip the `- ` depth prefix from titles, empty to null,
   `True`/`False` to booleans.
5. **ChapterFilter:** keep chapters from config/icd.php.
6. **HierarchyBuilder:** depth from the title prefix (not DepthInKind); a
   stack gives each row its parent URI; cross-check against the block
   ancestors in Grouping1-5 and against Parent when present.
7. **ReleaseImporter:** one transaction: delete this release's rows, bulk
   insert in chunks, build search vectors, run the diff, store changes,
   commit.
8. **Command output:** summary table, `--details` for the full list.

## Release files

Both releases ship with identical file names, so a file name says nothing
about the version. The header stamp is the only reliable marker. Downloads
are stored per version, gitignored, and reused. Only the .txt is read. An
unknown version (HTTP 404), a zip without a .txt, or a stamp mismatch stops
the import and writes nothing. The version argument must match `YYYY-MM`.

## Diff

Runs after the import, inside the same transaction, on the two snapshots in
the database. `ReleaseDiffer::diff(array $old, array $new): ChangeSet` is a
pure function over maps keyed by linearization URI.

- Parents compared by `parent_uri`, because `parent_id` is a per-release
  surrogate key.
- "Previous" is the highest imported version below the current one.
- Change types: added, removed, recoded, moved, code_reused, retitled
  (substantive), retitled (cosmetic: case or punctuation only).

## Search pipeline

1. **QueryClassifier:** normalize (lowercase, trim, hyphens to spaces outside
   codes); a regex decides code or code prefix vs free text.
2. **Strategies:** exact/prefix code; full-text over a weighted vector (own
   title weight A, ancestor titles weight C), all words required, prefix
   match on the last word; if fewer results than the limit, a fallback where
   any word may match, ranked by how many match; `pg_trgm` word similarity
   above a tuned threshold for typos.
3. **Tiers:** code > full-text (all words) > full-text (some words) > trigram.
4. **Tie-breaks:** primary-coding before supplementary, specific before
   residual, shorter title, then code.
5. **Deduplicate by entity**, keep the best tier, apply limit.
6. **Explain:** `matched_via` is `code`, `title`, `partial` or `fuzzy`.

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
  Http/Controllers/Api/{Search,Code,Release}Controller.php
  Http/Requests/SearchRequest.php
  Http/Resources/IcdEntityResource.php
config/icd.php
routes/api.php
tests/Fixtures/icd/
```

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
| Tree from title dashes | DepthInKind, Grouping1-5 | DepthInKind restarts per kind; Grouping lists only blocks (about 70% of parent links are category under category) |
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
- WHO titles and codes are served unmodified; stripping the depth prefix,
  BOM and whitespace are technical format changes; filtering to four
  chapters is a selection.
- Any future synonyms stay separate data and are never merged into titles.
- No own translations; Swedish must come from an official translation.
