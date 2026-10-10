## ADDED Requirements

### Requirement: Searchable entities
The system SHALL return categories (entities with a code) by default, and
SHALL include blocks only when asked.

#### Scenario: Blocks excluded by default
- **WHEN** a query matches both a block and categories under it
- **THEN** only the categories are returned, unless include_blocks=1

### Requirement: Code lookup
The system SHALL match exact codes and code prefixes.

#### Scenario: Prefix
- **WHEN** the query is `6A7`
- **THEN** categories whose code starts with 6A7 are returned first

### Requirement: Full-text search with ancestor context
The system SHALL search a vector built from the entity's own title with high
weight and its ancestors' titles with low weight, using English stemming and
requiring all words.

#### Scenario: Lay English phrase
- **WHEN** the query is "back pain"
- **THEN** low back pain entities are among the top results

#### Scenario: Words split between child and parent
- **WHEN** the query is "back pain sciatica"
- **THEN** ME84.20 Lumbago with sciatica is the first result, although its
  own title lacks "back" and "pain"

#### Scenario: Context word only in parent
- **WHEN** the query is "knee meniscus"
- **THEN** FA33.0 Cystic meniscus and FA33.1 Discoid meniscus are returned

#### Scenario: Plural and possessive forms
- **WHEN** the query is "alzheimers onset"
- **THEN** 6D80.0, 6D80.1 and 6D80.Z are returned, with 6D80.Z last

#### Scenario: Partial last word
- **WHEN** the query is "depr"
- **THEN** depressive disorder entities are returned, so a client can build
  typeahead on the same endpoint

### Requirement: Partial-match fallback
When fewer results than the limit match all words, the system SHALL add
results matching some of the words, ranked by how many match.

#### Scenario: Extra word not in any title
- **WHEN** the query is "alzheimers onset memory"
- **THEN** 6D80 entities are still returned, with matched_via partial

### Requirement: Typo tolerance
The system SHALL return close matches for misspelled queries.

#### Scenario: Misspelling
- **WHEN** the query is "depresion"
- **THEN** depressive disorder entities are among the top results with
  matched_via fuzzy

### Requirement: Ranking and explanation
The system SHALL rank code > full-text (all words) > full-text (some words)
> fuzzy, return each entity once, break ties by primary coding before
supplementary, specific before residual, shorter title, then code, and state
how each hit matched.

#### Scenario: Deduplicated
- **WHEN** an entity matches by both code and title
- **THEN** it appears once, ranked by its best match

#### Scenario: Residual after specific
- **WHEN** a specific code and its residual sibling score equally
- **THEN** the specific code comes first

#### Scenario: Deterministic order
- **WHEN** the same query runs twice
- **THEN** the results are in the same order

### Requirement: Chapters treated equally
The system SHALL NOT rank by chapter, and SHALL return each hit's chapter.

#### Scenario: Chapter shown
- **WHEN** a search returns hits from several chapters
- **THEN** each hit includes its chapter and none is boosted for it

### Requirement: Release selection
The system SHALL search the latest imported release unless a release is
given.

#### Scenario: Older release
- **WHEN** release=2025-01 is given
- **THEN** only 2025-01 entities are returned
