## ADDED Requirements

### Requirement: Import a release
The system SHALL provide an Artisan command `icd:import {version}` that
imports a WHO SimpleTabulation MMS release, keeping only the chapters
configured in config/icd.php.

#### Scenario: Download and import
- **WHEN** `icd:import 2026-01` runs and the release URL is reachable
- **THEN** the zip is downloaded, entities in chapters 06, 15, 21 and 24 are
  stored for release 2026-01, and entities in other chapters are not

#### Scenario: Import from a local file
- **WHEN** `icd:import 2026-01 --file=path/to/release.zip` runs, or the path
  points at an extracted .txt
- **THEN** the local file is imported and no download is attempted

#### Scenario: Version stamp mismatch
- **WHEN** `icd:import 2026-01` is given a file whose header stamp says
  2025
- **THEN** the import stops with a message naming both versions, exits
  non-zero, and writes nothing

#### Scenario: Invalid version format
- **WHEN** `icd:import 2026` or `icd:import 26-01` runs
- **THEN** the command rejects it before any download, expecting YYYY-MM

#### Scenario: Unknown version
- **WHEN** `icd:import 2030-01` runs and WHO responds 404
- **THEN** the import stops with a message naming the URL, exits non-zero,
  and writes nothing

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
- **WHEN** the downloaded file does not start with the zip signature
- **THEN** the import stops with a clear message, deletes the file, and
  writes nothing

#### Scenario: Zip without data
- **WHEN** the zip contains no data .txt
- **THEN** the import stops with a clear message and writes nothing

### Requirement: Re-runnable import
The system SHALL produce an identical database state when the same release
file is imported more than once.

#### Scenario: Import twice
- **WHEN** the same release is imported twice
- **THEN** entity counts and contents are identical after both runs

#### Scenario: Unchanged file is skipped
- **WHEN** a release is imported and its sha256 matches the stored release
- **THEN** the import is skipped with a message, unless `--force` is given

### Requirement: Changed file under an existing version
The system SHALL detect when the file for an already imported version has
changed, refuse to replace it by default, and report what changed when it
is replaced.

#### Scenario: Changed file refused
- **WHEN** a release is imported and its sha256 differs from the stored
  sha256 for the same version
- **THEN** the import stops with a message showing both hashes and the
  previous import date, exits non-zero, and writes nothing

#### Scenario: Changed file replaced with --force
- **WHEN** the same changed file is imported with `--force`
- **THEN** the new file is diffed against the stored snapshot of the same
  version, the changes are printed, the snapshot is replaced, and the diff
  for the next newer release is recomputed

### Requirement: Multiple releases side by side
The system SHALL store each release as its own snapshot.

#### Scenario: Two releases stored
- **WHEN** 2025-01 and 2026-01 are both imported
- **THEN** both releases are queryable and importing one does not change the
  other

### Requirement: Schema detection
The system SHALL map columns by header name, case-insensitively and with
known aliases, treat the version stamp cell as metadata, record the detected
column map on the release, and refuse files it does not understand.

#### Scenario: Both release formats parse identically
- **WHEN** fixtures with the 2025-01 header (17 columns) and the 2026-01
  header (19 columns, adding CodingNote and Parent) are read
- **THEN** both produce the same normalized entity shape, with coding_note
  null for 2025-01

#### Scenario: Missing required column
- **WHEN** a file lacks a required column
- **THEN** the import fails with a message naming the column, exits non-zero,
  and writes nothing

### Requirement: Record reassembly
The system SHALL reassemble records that span several physical lines because
a field contains unquoted line breaks.

#### Scenario: CodingNote with blank lines
- **WHEN** a record's CodingNote contains a blank line, so the record spans
  three physical lines
- **THEN** it is read as one entity with the full note, and no entity is
  created from the continuation lines

### Requirement: Hierarchy
The system SHALL derive each entity's depth from the dash prefix of its
title and its parent from file order, and SHALL NOT use DepthInKind as tree
depth.

#### Scenario: Parent assigned across class kinds
- **WHEN** a category with DepthInKind 1 follows a block at a shallower
  title depth
- **THEN** the category's parent is that block

#### Scenario: Parent column cross-check
- **WHEN** the file has a Parent column
- **THEN** every derived parent's foundation URI matches it, or the import
  reports the mismatches

#### Scenario: Grouping cross-check
- **WHEN** an entity lists block ancestors in Grouping1-5
- **THEN** they match the blocks on its derived path, or the import reports
  the mismatches

### Requirement: Atomic import
The system SHALL leave the previous state intact if an import fails.

#### Scenario: Failure midway
- **WHEN** the import fails after some rows were written
- **THEN** the transaction is rolled back and the release is unchanged
