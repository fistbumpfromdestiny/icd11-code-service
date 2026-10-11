# search-api Specification

## Purpose
Expose free-text search and code lookup over the imported releases as a JSON API, with WHO attribution on every successful response.

## Requirements

### Requirement: Search endpoint
The system SHALL expose GET /api/search with q (required, at least 2
characters after trimming), release (`YYYY-MM`), chapter (one of the
configured chapters), leaf_only, primary_only, include_blocks and limit (1
to 50, default 10), returning JSON. Each hit SHALL contain code (null for
blocks), block_id, title, class_kind, chapter, breadcrumb, is_leaf,
is_residual, primary_coding_allowed, matched_via and score.

#### Scenario: Valid search
- **WHEN** the search fixture is imported and GET /api/search?q=back+pain
- **THEN** the response is 200, every hit has the fields listed above, and
  meta contains the release used and the WHO attribution

#### Scenario: Block hit shape
- **WHEN** the search fixture is imported and
  GET /api/search?q=back+pain&include_blocks=1
- **THEN** the hit for BlockL1-ZZ0 has code null, block_id BlockL1-ZZ0 and
  class_kind block

#### Scenario: No results
- **WHEN** nothing matches
- **THEN** the response is 200 with an empty data array

#### Scenario: Invalid input
- **WHEN** q is missing, q is shorter than 2 characters after trimming
  (for example `  a `), limit is 0 or exceeds 50, chapter is not one of
  the configured chapters (for example 07), or release is not `YYYY-MM`
  (for example 2026)
- **THEN** the response is 422 with validation errors

#### Scenario: Query operator characters
- **WHEN** GET /api/search?q=back+%26+(pain+!
- **THEN** the response is 200 and no database error occurs

#### Scenario: Primary coding filter
- **WHEN** the search fixture is imported and q=back pain is requested with
  and without primary_only=1
- **THEN** ZZ01 is among the hits without the filter and absent with it

#### Scenario: Leaf-only filter
- **WHEN** the search fixture is imported and q=back pain and
  leaf_only=1 is given
- **THEN** every hit has is_leaf true, ME84.20 is among the hits and ME84.2
  is not

#### Scenario: Chapter filter
- **WHEN** the search fixture is imported and q=pain and chapter=21 is
  given
- **THEN** every hit has chapter 21, ME84.2 is among the hits and ZZ02 is
  not

#### Scenario: Blocks included
- **WHEN** the search fixture is imported and q=back pain and
  include_blocks=1 is given
- **THEN** BlockL1-ZZ0 is among the hits

### Requirement: Code endpoint
The system SHALL expose GET /api/codes/{code} with an optional release
parameter, returning the entity, its ancestors root first, its children,
its primary coding flag, and its coding note when the release has one.

#### Scenario: Ancestors and children
- **WHEN** the search fixture is imported and GET /api/codes/ME84.2
- **THEN** the response is 200, ancestors start with chapter 21 and end
  with ME84, children include ME84.20, and primary_coding_allowed is true

#### Scenario: Coding note shown
- **WHEN** search-2025.txt is imported as 2025-01, the search fixture as
  2026-01, and GET /api/codes/6D80.0 is requested with and without
  release=2025-01
- **THEN** the 2026-01 response has coding_note equal to 6D80.0's note in
  the fixture, starting "This category should never be used in primary
  tabulation.", and the 2025-01 response has coding_note null

#### Scenario: Unknown code
- **WHEN** the code does not exist in the selected release
- **THEN** the response is 404

### Requirement: Release errors
The system SHALL return 404 for a release parameter that is not imported,
and 503 when no release is imported at all.

#### Scenario: Unknown release
- **WHEN** GET /api/search?q=back+pain&release=2030-01 or
  GET /api/codes/6A70?release=2030-01, and 2030-01 is not imported
- **THEN** the response is 404 with a message naming 2030-01

#### Scenario: No release imported
- **WHEN** GET /api/search?q=back+pain and no release is imported
- **THEN** the response is 503 with the message "No ICD release imported"

### Requirement: Attribution
The system SHALL include `meta.attribution` with the WHO attribution on
every successful JSON response.

#### Scenario: Attribution on every endpoint
- **WHEN** the search fixture is imported and GET /api/search?q=back+pain
  and GET /api/codes/ME84.2 return 200
- **THEN** both bodies contain meta.attribution
