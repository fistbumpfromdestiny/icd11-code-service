## 0. Data investigation
- [x] 0.1 Download both releases, read both readme.txt
- [x] 0.2 Diff headers, count rows per chapter and ClassKind
- [x] 0.3 Write notes/data-findings.md

## 1. Skeleton
- [x] 1.1 Laravel 13 on PHP 8.5 (zip and pdo_pgsql extensions, container runs as host user), Pest, Docker Compose with PostgreSQL 17
- [x] 1.2 .gitattributes (LF, fixtures marked -text), .env.example, config/icd.php (chapters, release_url, search.trigram_threshold), .gitignore for storage/app/icd/
- [x] 1.3 Test: database is PostgreSQL and pg_trgm is available

## 2. Reading and parsing (icd-import)
- [x] 2.1 Fixtures: 2025-01 header, 2026-01 header, a record broken by line breaks in CodingNote, an aliased header, an unknown extra column, no Version cell, a record with too many fields, a file ending inside a record, a Parent mismatch, a Grouping mismatch and a depth jump
- [x] 2.2 ReleaseReader (zip to record generator, BOM, CRLF, record reassembly, quoted TSV, fail with the physical line number on too many fields or end of file inside a record)
- [x] 2.3 SchemaDetector (case-insensitive header map with aliases, unknown columns ignored and recorded in column_map, version stamp mapped to YYYY-MM, optional columns, fail on missing columns or missing Version cell)
- [x] 2.4 RowNormalizer and ChapterFilter
- [x] 2.5 HierarchyBuilder (depth from title prefix, parent_uri from stack; fail on a Parent or Grouping mismatch or a depth jump of more than one level)

## 3. Import command (icd-import)
4.1 and 4.2 are done before this group: ReleaseImporter runs the diff inside its transaction.

- [ ] 3.1 Migrations: releases, icd_entities
- [ ] 3.2 ReleaseSource (YYYY-MM validation with month 01 to 12, 5xx or timeout after retries, URL from `release_url` in config/icd.php, download to storage/app/icd/{version}/release.zip with Http::sink, zip signature check (only files downloaded in this run are deleted), truncated zip, several candidate .txt files, reuse cached zip, cached zip with the wrong stamp stops without deleting it, --fresh, --file for zip or .txt, missing --file path fails before any download, data .txt picked by extension excluding readme.txt and read via ZipArchive::getStream, sha256; tests use Http::fake with a zip built from fixtures)
- [ ] 3.3 ReleaseImporter (transaction, chunked insert, skip unchanged sha256 unless --force; --force on an unchanged file gives an identical result)
- [ ] 3.4 Tests: --force on an unchanged file is identical, two releases separate, rollback when a faked ReleaseDiffer throws
- [ ] 3.5 Changed file under same version: refuse by default; --force prints the diff against the stored snapshot without storing it, replaces the snapshot and recomputes this release's and the next release's diffs
- [ ] 3.6 Manual real-data check, not a Pest test: import both releases; check counts against notes/data-findings.md (3 516 and 3 512 in scope)

## 4. Release diff (release-diff)
- [x] 4.1 Migration: release_changes
- [x] 4.2 ReleaseDiffer as a pure function, one test per change type (added, removed, recoded, moved, code_reused, retitled, retitled_cosmetic), several changes on one URI, leaving the configured chapters counts as removed; cosmetic rule (lowercase, non-alphanumerics to spaces, collapse whitespace); diff fixtures tests/Fixtures/icd/diff-2025-01.txt, diff-2026-01.txt and diff-2027-01.txt
- [ ] 4.3 Previous-by-version logic, out-of-order test, release imported between two others recomputes the newer one's diff
- [ ] 4.4 Console summary and --details
- [ ] 4.5 Manual real-data check, not a Pest test: 2025-01 to 2026-01 run (expect 0 added, 4 removed, 0 recoded, 0 moved, 0 code_reused, 86 retitled, 2 retitled_cosmetic); save output for the README

## 5. Search (code-search)
- [ ] 5.1 Migrations: search_vector, GIN and trigram indexes, pg_trgm extension
- [ ] 5.2 Weighted search_vector: own title (A) plus ancestor titles (C), built at import
- [ ] 5.3 Fixtures tests/Fixtures/icd/search.txt and search-2025.txt with the rows listed in design.md (real rows and 6D80.0's coding note copied verbatim from the releases)
- [ ] 5.4 QueryClassifier (normalization, code detection, code queries upper-cased)
- [ ] 5.5 CodeSearch: code, all-words full-text with partial last word, some-words fallback, trigram above the fixed `search.trigram_threshold` in config/icd.php; tiers, exact code first, tie-break comparator as a pure function tested over five synthetic hits, dedupe, matched_via
- [ ] 5.6 Release selection: latest imported release by default, release parameter, 404 for an unknown release, 503 when none is imported
- [ ] 5.7 Choose the trigram_threshold value against real queries; note ranking decisions

## 6. API (search-api)
- [ ] 6.1 SearchRequest (trimmed q of at least 2 characters, release as YYYY-MM, chapter in the configured chapters, limit 1 to 50), IcdEntityResource (code null for blocks, block_id, class_kind, is_leaf), meta.attribution on every successful response
- [ ] 6.2 GET /api/search and GET /api/codes/{code} (with release parameter)
- [ ] 6.3 Feature tests: shapes (including a block hit), code endpoint ancestors and children, coding note per release, empty result, 422, 404 for unknown code and unknown release, 503 with no release, tsquery operator characters, leaf_only, chapter, include_blocks, primary_only, attribution
- [ ] 6.4 icd:search command using the same CodeSearch, for README examples; test that it lists the same codes in the same order as the API

## 7. Search quality
- [ ] 7.1 Golden queries (20 to 30) with expected codes, including "back pain sciatica", "knee meniscus", "alzheimers onset", "depresion", "6A7" and the expected miss "tennis elbow"
- [ ] 7.2 Pest test reporting recall@5 and misses

## 8. Documentation and verification
- [ ] 8.1 README: install, import (including how to place a release zip in storage/app/icd/{version}/ for offline use), tests, 5 to 6 real example searches, and a "Data and licence" section
- [ ] 8.2 DECISIONS.md: fill the TODOs
- [ ] 8.3 Optional: GitHub Actions running the tests
- [ ] 8.4 Fresh clone into an empty folder, follow README literally
