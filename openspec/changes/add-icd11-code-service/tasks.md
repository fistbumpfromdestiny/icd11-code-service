## 0. Data investigation
- [x] 0.1 Download both releases, read both readme.txt
- [x] 0.2 Diff headers, count rows per chapter and ClassKind
- [x] 0.3 Write notes/data-findings.md

## 1. Skeleton
- [ ] 1.1 Laravel 13 on PHP 8.5 (zip and pdo_pgsql extensions, container runs as host user), Pest, Docker Compose with PostgreSQL 17
- [ ] 1.2 .gitattributes (LF, fixtures marked -text), .env.example, config/icd.php, .gitignore for storage/app/icd/
- [ ] 1.3 Test: database is PostgreSQL and pg_trgm is available

## 2. Reading and parsing (icd-import)
- [ ] 2.1 Fixtures: 2025-01 header, 2026-01 header, and a record broken by line breaks in CodingNote
- [ ] 2.2 ReleaseReader (zip to record generator, BOM, CRLF, record reassembly, quoted TSV)
- [ ] 2.3 SchemaDetector (case-insensitive header map with aliases, version stamp, optional columns, fail on missing columns)
- [ ] 2.4 RowNormalizer and ChapterFilter
- [ ] 2.5 HierarchyBuilder (depth from title prefix, parent_uri from stack, cross-check Parent and Grouping)

## 3. Import command (icd-import)
- [ ] 3.1 Migrations: releases, icd_entities
- [ ] 3.2 ReleaseSource (YYYY-MM validation, download to storage/app/icd/{version}/release.zip with Http::sink, zip signature check, reuse cached zip, --fresh, --file for zip or .txt, data .txt picked by extension excluding readme.txt and read via ZipArchive::getStream, sha256; tests use Http::fake with a zip built from fixtures)
- [ ] 3.3 ReleaseImporter (transaction, chunked insert, skip unless --force)
- [ ] 3.4 Tests: import twice identical, two releases separate, rollback on failure
- [ ] 3.5 Changed file under same version: refuse by default; --force diffs against the stored snapshot, replaces it and recomputes the next release's diff
- [ ] 3.6 Optional: release_imports table (version, sha256, row count, imported_at) as an audit trail
- [ ] 3.7 Real import of both releases; check counts against notes/data-findings.md (3 516 and 3 512 in scope)

## 4. Release diff (release-diff)
- [ ] 4.1 Migration: release_changes
- [ ] 4.2 ReleaseDiffer as a pure function, one test per change type, cosmetic vs substantive retitles
- [ ] 4.3 Previous-by-version logic, out-of-order test
- [ ] 4.4 Console summary and --details
- [ ] 4.5 Real 2025-01 to 2026-01 run (expect 0 added, 4 removed, 0 recoded, 0 moved, 0 reused, 88 retitled); save output for the README

## 5. Search (code-search)
- [ ] 5.1 Migrations: search_vector, GIN and trigram indexes, pg_trgm extension
- [ ] 5.2 Weighted search_vector: own title (A) plus ancestor titles (C), built at import
- [ ] 5.3 QueryClassifier (normalization, code detection)
- [ ] 5.4 CodeSearch: code, all-words full-text with partial last word, some-words fallback, trigram; tiers, tie-breaks, dedupe, matched_via
- [ ] 5.5 Tune against real queries; note ranking decisions

## 6. API (search-api)
- [ ] 6.1 SearchRequest, IcdEntityResource, WHO attribution in meta
- [ ] 6.2 GET /api/search and GET /api/codes/{code}
- [ ] 6.3 Feature tests: shapes, empty result, 422, 404
- [ ] 6.4 icd:search command using the same CodeSearch, for README examples
- [ ] 6.5 Optional: GET /api/releases and /changes

## 7. Search quality
- [ ] 7.1 Golden queries (20 to 30) with expected codes, including "back pain sciatica", "knee meniscus", "alzheimers onset", "depresion", "6A7" and the expected miss "tennis elbow"
- [ ] 7.2 Pest test reporting recall@5 and misses

## 8. Documentation and verification
- [ ] 8.1 README: install, import (including how to place a release zip in storage/app/icd/{version}/ for offline use), tests, 5 to 6 real example searches, and a "Data and licence" section
- [ ] 8.2 DECISIONS.md: fill the TODOs
- [ ] 8.3 Optional: GitHub Actions running the tests
- [ ] 8.4 Fresh clone into an empty folder, follow README literally
