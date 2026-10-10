## ADDED Requirements

### Requirement: Searchable entities
The system SHALL return categories (entities with a code) by default, and
SHALL include blocks only when asked.

#### Scenario: Blocks excluded by default
- **WHEN** the search fixture is imported, the query is "back pain", and
  include_blocks is not given
- **THEN** block BlockL1-ZZ0 is not among the hits and no hit has
  class_kind block

### Requirement: Code lookup
The system SHALL match exact codes and code prefixes case-insensitively.
Within the code tier, an exact match SHALL come first, then the other
matches in code order.

#### Scenario: Prefix
- **WHEN** the search fixture is imported and the query is `6A7`
- **THEN** every hit's code starts with 6A7, every hit has matched_via
  code, and hits are ordered by code ascending

#### Scenario: Exact code first
- **WHEN** the search fixture is imported and the query is `6A70`
- **THEN** 6A70 is hit 1 with matched_via code, followed by the codes
  starting with 6A70 in code order

#### Scenario: Lower-case code
- **WHEN** the search fixture is imported and the query is `6a7`
- **THEN** the hits are the same, in the same order, as for `6A7`

### Requirement: Full-text search with ancestor context
The system SHALL search a vector built from the entity's own title with high
weight and its ancestors' titles with low weight, using English stemming and
requiring all words.

#### Scenario: Lay English phrase
- **WHEN** the search fixture is imported and the query is "back pain"
- **THEN** ME84.2 is in the top 3 with matched_via title

#### Scenario: Words split between child and parent
- **WHEN** the search fixture is imported and the query is
  "back pain sciatica"
- **THEN** ME84.20 Lumbago with sciatica is hit 1, although its own title
  lacks "back" and "pain"

#### Scenario: Context word only in parent
- **WHEN** the search fixture is imported and the query is
  "knee meniscus"
- **THEN** FA33.0 Cystic meniscus and FA33.1 Discoid meniscus are in the
  top 5

#### Scenario: Plural and possessive forms
- **WHEN** the search fixture is imported and the query is
  "alzheimers onset"
- **THEN** 6D80.0 and 6D80.1 are hits 1 and 2 in either order, and 6D80.Z
  is hit 3

#### Scenario: Partial last word
- **WHEN** the search fixture is imported and the query is "depr"
- **THEN** 6A70 and 6A71 are in the top 5 with matched_via title

### Requirement: Partial-match fallback
When fewer results than the limit match all words, the system SHALL add
results matching some of the words, ranked by how many match.

#### Scenario: Extra word not in any title
- **WHEN** the search fixture is imported and the query is
  "alzheimers onset memory"
- **THEN** 6D80.0, 6D80.1 and 6D80.Z are returned with matched_via partial,
  and no partial hit ranks above a title hit

### Requirement: Typo tolerance
The system SHALL return close matches for misspelled queries, using
`pg_trgm` word similarity above the fixed `search.trigram_threshold` in
config/icd.php. Close matches SHALL be searched only when the code, title
and partial tiers found nothing, so they never pad out real matches.

#### Scenario: Misspelling
- **WHEN** the search fixture is imported and the query is "depresion"
- **THEN** 6A70 is in the top 5 with matched_via fuzzy

#### Scenario: No close matches next to real matches
- **WHEN** the search fixture is imported and the query is "pain", with
  and without chapter 15
- **THEN** no hit has matched_via fuzzy (without the chapter, the 6A7
  "... partial remission" titles would otherwise be fuzzy hits)

### Requirement: Ranking and explanation
The system SHALL rank code > full-text (all words) > full-text (some words)
> fuzzy, return each entity once with its best match, and state how each
hit matched. Within the full-text and fuzzy tiers, hits with equal score
SHALL be ordered by primary coding before supplementary, specific before
residual, shorter title, code ascending, then block_id ascending (blocks
have no code).

#### Scenario: Deduplicated
- **WHEN** the search fixture is imported and the query is "depressive"
- **THEN** 6A70 appears exactly once, with matched_via title

#### Scenario: Tie-break order
- **WHEN** the tie-break comparator is given five hits with equal tier and
  score: A (supplementary), B (primary, residual), C (primary, specific,
  title length 20, code Q2), D (primary, specific, title length 20, code
  Q1) and E (primary, specific, title length 10)
- **THEN** the order is E, D, C, B, A

#### Scenario: Tie-break between blocks
- **WHEN** the tie-break comparator is given two blocks (code null) with
  equal tier, score, flags and title length, block ids BlockL1-ZZ1 and
  BlockL1-ZZ0, in that order
- **THEN** the order is BlockL1-ZZ0, BlockL1-ZZ1

### Requirement: Search quality
The system SHALL be measured against a golden query set of 15 to 25
queries run on the real 2026-01 release, reporting recall@5 for the
expected hits. An expected hit missing from the top 5 SHALL fail the
check; expected misses SHALL be reported and never fail.

#### Scenario: Expected hit through one word
- **WHEN** the real 2026-01 release is imported and the query is
  "tennis elbow"
- **THEN** FB55.1 Lateral epicondylitis of elbow is in the top 5, found
  through "elbow" with matched_via partial

#### Scenario: Expected miss
- **WHEN** the real 2026-01 release is imported and the query is
  "fibromialgia"
- **THEN** a miss of MG30.01 Chronic widespread pain is reported and does
  not fail the check, since the query shares no word with the title and no
  synonyms exist in this version

#### Scenario: Release file not present
- **WHEN** storage/app/icd/2026-01/release.zip does not exist
- **THEN** the golden check is skipped, never downloads and never fails

### Requirement: Chapters treated equally
The system SHALL NOT rank by chapter, and SHALL return each hit's chapter.

#### Scenario: Chapter shown
- **WHEN** the search fixture is imported and the query is "pain"
- **THEN** every hit has a chapter field

### Requirement: Release selection
The system SHALL search the latest imported release unless a release is
given.

#### Scenario: Latest by default
- **WHEN** search-2025.txt is imported as 2025-01, the search fixture as
  2026-01, and no release is given
- **THEN** only 2026-01 entities are returned

#### Scenario: Older release
- **WHEN** both search fixtures are imported and release=2025-01 is given
- **THEN** only 2025-01 entities are returned
