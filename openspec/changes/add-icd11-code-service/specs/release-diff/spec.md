## ADDED Requirements

### Requirement: Diff against the previous release
After each import, the system SHALL compare the release with the highest
imported version below it, matching entities by linearization URI, and
store the changes.

#### Scenario: Added and removed
- **WHEN** an entity exists only in the newer release, or only in the older
- **THEN** it is reported as added, or removed

#### Scenario: Recoded
- **WHEN** the same URI has a different code in the newer release
- **THEN** it is reported as recoded with the old and new code

#### Scenario: Substantive retitle
- **WHEN** the same URI has a title that differs in wording
- **THEN** it is reported as retitled with old and new title

#### Scenario: Cosmetic retitle
- **WHEN** the titles differ only in case or punctuation
- **THEN** it is reported as a cosmetic retitle, counted separately

#### Scenario: Moved
- **WHEN** the same URI has a different parent URI
- **THEN** it is reported as moved

#### Scenario: Code reused for a different entity
- **WHEN** a code points to a different URI than in the previous release
- **THEN** it is reported as code_reused and highlighted in the summary

#### Scenario: Out-of-order import
- **WHEN** 2026-01 is imported before 2025-01
- **THEN** 2025-01 has no predecessor and the 2026-01 diff is recomputed
  against 2025-01

### Requirement: Change report
The import command SHALL print a summary of changes per chapter and change
type, and the full list with `--details`.

#### Scenario: Summary printed
- **WHEN** 2026-01 is imported after 2025-01
- **THEN** a table of counts per chapter and change type is printed
  (expected from the real files: 0 added, 4 removed, 0 recoded, 0 moved,
  0 code reused, 88 retitled)

#### Scenario: First release
- **WHEN** a release has no predecessor
- **THEN** the command states there is nothing to compare against
