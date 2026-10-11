# ICD-11 Code Service

A Laravel 13 service that imports WHO ICD-11 MMS release files (chapters 06,
15, 21, 24) into PostgreSQL and exposes a JSON search API for occupational
health nurses.

## Where things are

- `openspec/specs/`: the main specs (icd-import, release-diff, code-search,
  search-api). The source of truth for behaviour.
- `openspec/changes/archive/2026-10-11-add-icd11-code-service/`: the
  archived first change (proposal, design, tasks). New work goes in a new
  change under `openspec/changes/`.
- `notes/data-findings.md`: verified facts about the WHO files. Read it
  before touching the import. Never guess the file format.
- `DECISIONS.md`: the choices and why. Keep it short.

## Stack

- PHP 8.5, Laravel 13, PostgreSQL 17 (full-text search, pg_trgm), Pest.
- Everything runs in Docker Compose. Run commands inside the app container.

## Commands

- Start: `docker compose up -d`
- Tests: `docker compose exec app php artisan test`
- Import: `docker compose exec app php artisan icd:import 2026-01`
- Search: `curl "http://localhost:8000/api/search?q=back+pain"`

## Reviewers

- `spec-reviewer` (in `.claude/agents/`): run before implementation starts
  and after any change to the spec.
- `code-reviewer`: run on the diff before every commit. Fix blockers before
  committing.

## How to work

- Work one task group from `tasks.md` at a time. Tick tasks when done.
- Use plan mode first and wait for approval before writing code.
- Test first: write the Pest tests from the spec scenarios, show them, then
  implement.
- If real data contradicts the spec, stop and say so. The spec is updated
  first, then the code.
- One commit per completed task group, with a clear message.

## Rules

- Tests run against PostgreSQL, never SQLite.
- Never hardcode column positions. Map columns by header name,
  case-insensitively, with aliases.
- Identity across releases is the linearization URI, never the code.
- Build the tree from the `- ` prefixes in the title. Do not use
  DepthInKind. Parent and Grouping1-5 are cross-checks only.
- Reassemble records by field count before parsing (CodingNote contains raw
  line breaks in 2026-01).
- WHO titles and codes are stored and served unmodified, apart from
  technical normalisation: stripping the BOM, the depth prefix and
  surrounding whitespace.
- No synonyms in this version.
- Domain logic lives in `app/Icd/{Import,Diff,Search}`. Commands and
  controllers stay thin.
- No WHO release files in the repository. Fixtures are small. Search
  fixtures may contain a handful of verbatim WHO rows (titles, URIs, flags
  and coding notes copied exactly, never modified). Synthetic rows use `ZZ`
  codes, "Synthetic" titles and the `.../mms/synthetic/` URI prefix. Never
  whole release files, never real rows with altered content. Tests never
  use the network; fake the WHO download with `Http::fake()`.
- The container only sees the project folder. A local release file goes in
  `storage/app/icd/{version}/release.zip`.
- The version argument is validated as `YYYY-MM` before it is used in a URL
  or path. Search input reaches SQL only through bound parameters.
- No new dependencies without asking.
- Never edit DECISIONS.md. Fredrik writes it. Suggest changes in chat
  instead.
