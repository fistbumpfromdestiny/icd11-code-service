# Change: Add ICD-11 code service

## Why
Sweden is moving from ICD-10 to ICD-11. Occupational health nurses record
sick leave reasons as diagnosis codes but think in everyday words, not
clinical terms. They need a service that finds the right ICD-11 code for the
most common causes of sick leave, mental illness and musculoskeletal
problems, from the words they actually type.

## What Changes
- Artisan command `icd:import {version}` that downloads or reads a WHO
  SimpleTabulation release, detects its schema from the headers, and imports
  chapters 06, 15, 21 and 24 into PostgreSQL. Re-runnable; supports multiple
  releases side by side.
- Release diff computed after each import against the previous release by
  version, stored, and printed as a summary with details on request.
- Search over local data combining code lookup, English full-text search
  with ancestor context, a partial-match fallback and typo-tolerant trigram
  matching, ranked in tiers and explained per hit.
- JSON API: free-text search (required) and code lookup; release listing and
  change listing as optional additions.

## Impact
- Affected specs: icd-import, release-diff, code-search, search-api (all new)
- Affected code: app/Icd/*, app/Console/Commands, app/Http, database/,
  config/icd.php, tests/
- Data: WHO content under CC BY-ND 3.0 IGO, never modified, attributed in
  API responses
