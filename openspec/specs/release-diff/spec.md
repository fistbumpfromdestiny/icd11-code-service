# release-diff Specification

## Purpose
Compare each imported release with the previous release by version, matching entities by linearization URI, store the changes and report them as a summary with details on request.

## Requirements

### Requirement: Diff against the previous release
After each import, the system SHALL compare the release with the highest
imported version below it, matching entities by linearization URI, and
store the changes. Change types SHALL be `added`, `removed`, `recoded`,
`moved`, `code_reused`, `retitled` and `retitled_cosmetic`. One URI SHALL
produce one change record per change type that applies to it. Two
different titles SHALL be a cosmetic retitle when they are equal after
lowercasing, replacing every character that is not a letter or digit with a
space, and collapsing whitespace; otherwise they are a retitle.

#### Scenario: Added
- **WHEN** an entity's URI exists only in the newer release
- **THEN** it is reported as added

#### Scenario: Removed
- **WHEN** an entity's URI exists only in the older release
- **THEN** it is reported as removed

#### Scenario: Left the configured chapters
- **WHEN** an entity's URI is in a configured chapter in the older release
  and in another chapter in the newer release
- **THEN** it is reported as removed

#### Scenario: Recoded
- **WHEN** the same URI has a different code in the newer release
- **THEN** it is reported as recoded with the old and new code

#### Scenario: Substantive retitle
- **WHEN** the same URI changes title from "Fasciitis and fibromatosis" to
  "Fasciitis or fibromatosis"
- **THEN** it is reported as retitled with old and new title

#### Scenario: Cosmetic retitle
- **WHEN** the same URI changes title from "Ketamine" to "ketamine", or
  from "Abnormal blood-pressure reading" to "Abnormal blood pressure
  reading"
- **THEN** it is reported as retitled_cosmetic with old and new title

#### Scenario: Moved
- **WHEN** the same URI has a different parent URI
- **THEN** it is reported as moved

#### Scenario: Several changes on one entity
- **WHEN** the same URI has a different code, a different title and a
  different parent
- **THEN** three change records are stored for it: recoded, retitled and
  moved

#### Scenario: Code reused for a different entity
- **WHEN** a code belongs to URI A in the older release and to URI B in the
  newer release
- **THEN** a code_reused record is stored naming the code and both URIs,
  and it is highlighted in the summary

#### Scenario: Out-of-order import
- **WHEN** diff-2026-01.txt is imported before diff-2025-01.txt
- **THEN** 2025-01 has no predecessor and the 2026-01 diff is recomputed
  against 2025-01, giving the same changes as an in-order import

#### Scenario: Release imported between two others
- **WHEN** diff-2025-01.txt and diff-2027-01.txt are imported, then
  diff-2026-01.txt
- **THEN** 2026-01 is diffed against 2025-01, and the 2027-01 diff is
  recomputed against 2026-01 and contains exactly one retitled record

### Requirement: Change report
The import command SHALL print a summary of changes per chapter and change
type, and the full list with `--details`.

#### Scenario: Summary printed
- **WHEN** diff-2025-01.txt and then diff-2026-01.txt are imported
- **THEN** the summary has one row per change type, each with count 1

#### Scenario: Details printed
- **WHEN** diff-2025-01.txt is imported, then diff-2026-01.txt with
  `--details`
- **THEN** every change is listed with its type, code and before and after
  values

#### Scenario: First release
- **WHEN** a release has no predecessor
- **THEN** the command states there is nothing to compare against
