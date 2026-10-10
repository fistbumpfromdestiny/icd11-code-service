## ADDED Requirements

### Requirement: Search endpoint
The system SHALL expose GET /api/search with q (required, at least 2
characters), release, chapter, leaf_only, primary_only, include_blocks and
limit (default 10, maximum 50), returning JSON.

#### Scenario: Valid search
- **WHEN** GET /api/search?q=back+pain
- **THEN** the response is 200 with code, title, chapter, breadcrumb,
  is_residual, primary_coding_allowed, matched_via and score per hit, and
  meta with the release used and WHO attribution

#### Scenario: No results
- **WHEN** nothing matches
- **THEN** the response is 200 with an empty data array

#### Scenario: Invalid input
- **WHEN** q is missing or shorter than 2 characters, or limit exceeds 50
- **THEN** the response is 422 with validation errors

#### Scenario: Primary coding filter
- **WHEN** primary_only=1 is given
- **THEN** codes marked as not for primary tabulation are excluded

### Requirement: Code endpoint
The system SHALL expose GET /api/codes/{code} returning the entity, its
ancestors, its children, its primary coding flag, and its coding note when
the release has one.

#### Scenario: Coding note shown
- **WHEN** a code with a coding note is requested from 2026-01
- **THEN** the response includes the note; for 2025-01 the field is null

#### Scenario: Unknown code
- **WHEN** the code does not exist in the selected release
- **THEN** the response is 404

### Requirement: Release and change endpoints
The system SHALL expose GET /api/releases and
GET /api/releases/{version}/changes, filterable by type and chapter. These
are optional additions beyond the brief.

#### Scenario: Changes listed
- **WHEN** GET /api/releases/2026-01/changes?type=removed
- **THEN** the removed entities from the 2025-01 to 2026-01 diff are returned
