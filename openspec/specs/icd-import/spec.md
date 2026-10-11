# icd-import Specification

## Purpose
Import WHO ICD-11 MMS SimpleTabulation releases (chapters 06, 15, 21 and 24) into PostgreSQL with the `icd:import {version}` command, detecting the schema from the headers, re-runnable and with multiple releases side by side.

## Requirements

### Requirement: Import a release
The system SHALL provide an Artisan command `icd:import {version}` that
imports a WHO SimpleTabulation MMS release, keeping only the chapters
configured in config/icd.php. The download URL SHALL be built from the
`release_url` template in config/icd.php.

#### Scenario: Download and import
- **WHEN** `icd:import 2026-01` runs and the URL built from `release_url`
  for 2026-01 returns a zip
- **THEN** the zip is downloaded, entities in chapters 06, 15, 21 and 24 are
  stored for release 2026-01, and entities in other chapters are not

#### Scenario: Import from a local file
- **WHEN** `icd:import 2026-01 --file=path/to/release.zip` runs, or the path
  points at an extracted .txt
- **THEN** the local file is imported and no download is attempted

#### Scenario: Local file missing
- **WHEN** `icd:import 2026-01 --file=path/that/does/not/exist.zip` runs
- **THEN** the import stops with a message naming the path, exits non-zero,
  makes no download, and writes nothing

#### Scenario: Invalid version format
- **WHEN** `icd:import 2026`, `icd:import 26-01` or `icd:import 2026-13`
  runs
- **THEN** the command rejects it before any download, expecting YYYY-MM
  with a month from 01 to 12

#### Scenario: Unknown version
- **WHEN** `icd:import 2030-01` runs and WHO responds 404
- **THEN** the import stops with a message naming the URL, exits non-zero,
  and writes nothing

#### Scenario: Server error or timeout
- **WHEN** WHO responds 5xx or times out on every attempt, including the
  retries
- **THEN** the import stops with a message naming the URL, exits non-zero,
  and writes nothing

### Requirement: Version stamp
The system SHALL map the header's `Version:` cell to `YYYY-MM` by its year
and month, and SHALL import a file only when that matches the requested
version.

#### Scenario: Stamp matches
- **WHEN** `icd:import 2026-01` is given a file stamped
  `Version:2026 Jan 17 - 05:30 UTC`
- **THEN** the stamp maps to 2026-01 and the import proceeds

#### Scenario: Unparseable stamp
- **WHEN** the header's version cell is `Version:sometime`
- **THEN** the import fails with a message quoting the cell, exits
  non-zero, and writes nothing

#### Scenario: Stamp mismatch
- **WHEN** `icd:import 2026-01` is given a file stamped
  `Version:2025 Jan 24 - 22:30 UTC`
- **THEN** the import stops with a message containing `2026-01` and
  `2025-01`, exits non-zero, and writes nothing

#### Scenario: Cached zip with the wrong stamp
- **WHEN** `icd:import 2026-01` runs and storage/app/icd/2026-01/release.zip
  is stamped `Version:2025 Jan 24 - 22:30 UTC`
- **THEN** the import stops with a message naming both versions and the
  path, telling the user to remove the file or rerun with `--fresh`; the
  file is not deleted, the command exits non-zero, and nothing is written

### Requirement: Release file handling
The system SHALL store downloaded release files per version outside the
repository, reuse them on later runs, and read only the tab-separated file
from the zip.

#### Scenario: Cached download reused
- **WHEN** `icd:import 2026-01` runs and storage/app/icd/2026-01/release.zip
  already exists
- **THEN** no download is made unless `--fresh` is given

#### Scenario: Only the data file is read
- **WHEN** the zip contains SimpleTabulation-ICD-11-MMS-en.txt, an .xlsx and
  a readme.txt
- **THEN** only the data .txt is parsed, chosen by extension with readme.txt
  excluded, and read from the zip without extracting it

#### Scenario: Download is not a zip
- **WHEN** the file downloaded in this run does not start with the zip
  signature
- **THEN** the import stops with a clear message, deletes the file, exits
  non-zero, and writes nothing

#### Scenario: Local or cached file is not a zip
- **WHEN** a `--file` ending in .zip, or a cached release.zip, does not
  start with the zip signature
- **THEN** the import stops with a message naming the path, exits
  non-zero, writes nothing, and does not delete the file

#### Scenario: Truncated zip
- **WHEN** the file starts with the zip signature but ZipArchive cannot open
  it
- **THEN** the import stops with a message naming the path, exits non-zero,
  and writes nothing; the file is deleted if it was downloaded in this run
  and kept otherwise

#### Scenario: Zip without data
- **WHEN** the zip contains no data .txt
- **THEN** the import stops with a clear message and writes nothing

#### Scenario: Several candidate data files
- **WHEN** the zip contains more than one .txt besides readme.txt
- **THEN** the import stops with a message listing them, exits non-zero,
  and writes nothing

### Requirement: Re-runnable import
The system SHALL produce an identical database state when the same release
file is imported more than once.

#### Scenario: Unchanged file is skipped
- **WHEN** a release is imported and its sha256 matches the stored release
- **THEN** the import is skipped with a message, nothing is written, and
  the command exits 0

#### Scenario: Unchanged file with --force
- **WHEN** a release is imported with `--force` and its sha256 matches the
  stored release
- **THEN** it is re-imported, and entity counts, entity contents and
  stored changes are identical to before

### Requirement: Changed file under an existing version
The system SHALL detect when the file for an already imported version has
changed, refuse to replace it by default, and print what changed when it is
replaced with `--force`. The same-version diff SHALL be printed only, not
stored.

#### Scenario: Changed file refused
- **WHEN** a release is imported and its sha256 differs from the stored
  sha256 for the same version
- **THEN** the import stops with a message showing both hashes and the
  previous import date, exits non-zero, and writes nothing

#### Scenario: Changed file replaced with --force
- **WHEN** the same changed file is imported with `--force`
- **THEN** the new file is diffed against the stored snapshot of the same
  version and the changes are printed, no same-version changes are stored,
  the snapshot is replaced, and the stored diffs of this release and of the
  next newer release are recomputed

### Requirement: Multiple releases side by side
The system SHALL store each release as its own snapshot.

#### Scenario: Two releases stored
- **WHEN** 2025-01 and 2026-01 are both imported
- **THEN** both releases are queryable and importing one does not change the
  other

### Requirement: Schema detection
The system SHALL map columns by header name, case-insensitively and with
known aliases, treat the `Version:` cell as metadata, and record the
detected column map on the release. Required columns: Linearization URI,
Foundation URI, Code, BlockId, Title, ClassKind, IsResidual, ChapterNo,
isLeaf, Primary tabulation, Grouping1 to Grouping5. Optional columns:
CodingNote, Parent. Ignored columns: DepthInKind, BrowserLink. Unknown
extra columns SHALL be ignored and recorded in the column map. The system
SHALL refuse a file with a missing required column, a missing `Version:`
cell, or malformed records.

#### Scenario: Both release formats parse identically
- **WHEN** fixtures with the 2025-01 header (17 columns) and the 2026-01
  header (19 columns, adding CodingNote and Parent) are read
- **THEN** both produce the same normalized entity shape, with coding_note
  null for 2025-01

#### Scenario: Header aliases
- **WHEN** a header uses `Linearization (release) URI`, `IsLeaf` and
  `Primary Tabulation`
- **THEN** it parses the same as one using `Linearization URI`, `isLeaf` and
  `Primary tabulation`

#### Scenario: Unknown extra column
- **WHEN** a header contains a column the system does not know
- **THEN** the column is ignored, its name is recorded in column_map, and
  the import succeeds

#### Scenario: Missing required column
- **WHEN** a file's header lacks `ChapterNo`
- **THEN** the import fails with a message containing `ChapterNo`, exits
  non-zero, and writes nothing

#### Scenario: Missing version stamp
- **WHEN** the header has no `Version:` cell
- **THEN** the import fails with a message saying the stamp is missing,
  exits non-zero, and writes nothing

### Requirement: Record reassembly
The system SHALL reassemble records that span several physical lines, and
SHALL fail on records that cannot be reassembled to the header's field
count.

#### Scenario: CodingNote with blank lines
- **WHEN** a record's CodingNote contains a blank line, so the record spans
  three physical lines
- **THEN** it is read as one entity with the full note, and no entity is
  created from the continuation lines

#### Scenario: Record with too many fields
- **WHEN** a record has more fields than the header
- **THEN** the import fails with a message naming the physical line number
  where the record starts, exits non-zero, and writes nothing

#### Scenario: File ends inside a record
- **WHEN** the file ends before the last record reaches the header's field
  count
- **THEN** the import fails with a message naming the physical line number
  where the record starts, exits non-zero, and writes nothing

### Requirement: Hierarchy
The system SHALL derive each entity's depth from the dash prefix of its
title and its parent from file order, and SHALL NOT use DepthInKind as tree
depth. Any mismatch with the Parent or Grouping1-5 columns, or a title
depth more than one level below the previous row, SHALL fail the import.

#### Scenario: Parent assigned across class kinds
- **WHEN** a category with DepthInKind 1 follows a block at a shallower
  title depth
- **THEN** the category's parent is that block

#### Scenario: Parent column mismatch
- **WHEN** the file has a Parent column and a derived parent's foundation
  URI differs from it
- **THEN** the import fails listing each mismatching code, exits non-zero,
  and writes nothing

#### Scenario: Grouping mismatch
- **WHEN** an entity's Grouping1-5 block ids differ from the block ids of
  its derived ancestor blocks, excluding its own BlockId
- **THEN** the import fails listing each mismatching code, exits non-zero,
  and writes nothing

#### Scenario: Depth jump
- **WHEN** a row's title depth is more than the previous row's depth plus
  one
- **THEN** the import fails naming the row's code or block id, exits
  non-zero, and writes nothing

### Requirement: Atomic import
The system SHALL leave the previous state intact if an import fails.

#### Scenario: Failure midway
- **WHEN** 2026-01 is imported from one fixture, then re-imported with
  `--force` from a changed fixture while ReleaseDiffer is replaced by a
  fake that throws
- **THEN** the command exits non-zero, and the 2026-01 entity rows, sha256
  and stored changes equal those after the first import
