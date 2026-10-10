# ICD-11 Code Service

Imports WHO's ICD-11 release files into PostgreSQL and serves a JSON API for
searching codes by code, title words or misspellings.

- Chapters: 06 (mental, behavioural or neurodevelopmental disorders),
  15 (musculoskeletal system), 21 (symptoms, signs or clinical findings) and
  24 (factors influencing health status)
- Releases: 2025-01 and 2026-01, side by side
- Import reports what changed compared with the previous release
- JSON API for free-text and code search

Why it is built the way it is: [DECISIONS.md](DECISIONS.md). What the WHO
files actually contain: [notes/data-findings.md](notes/data-findings.md).

## Requirements

Docker with Compose. Nothing else: PHP 8.5 and PostgreSQL 17 run in
containers.

## Getting started

```bash
git clone https://github.com/fistbumpfromdestiny/icd11-code-service.git
cd icd11-code-service
cp .env.example .env            # set UID and GID to the output of id -u and id -g
docker compose up -d --build    # installs dependencies and runs migrations
docker compose exec app php artisan key:generate
```

The API is now on http://localhost:8000 and PostgreSQL on port 5433. Both
ports can be changed in `.env` (`APP_PORT`, `DB_FORWARD_PORT`).

## Importing releases

```bash
docker compose exec app php artisan icd:import 2025-01
docker compose exec app php artisan icd:import 2026-01
```

The command downloads the release from WHO, keeps only the four chapters, and
stores it as its own snapshot. Running it again with the same file does
nothing; `--force` re-imports and gives the same result.

**Without network access**, put the release zip where the import looks for a
cached download:

```bash
mkdir -p storage/app/icd/2026-01
cp SimpleTabulation-ICD-11-MMS-en.zip storage/app/icd/2026-01/release.zip
```

You can also point at a file inside the project with `--file=path/to/file.zip`
(or an extracted `.txt`). The release files are never committed.

### What changed between 2025-01 and 2026-01

Importing 2026-01 after 2025-01 prints a summary per change type and chapter:

```
Imported 2026-01 from storage/app/icd/2026-01/release.zip: 3512 entities.
Changes against 2025-01:
+-------------------+----+----+----+----+-------+
| Change            | 06 | 15 | 21 | 24 | Total |
+-------------------+----+----+----+----+-------+
| added             | 0  | 0  | 0  | 0  | 0     |
| removed           | 0  | 3  | 0  | 1  | 4     |
| recoded           | 0  | 0  | 0  | 0  | 0     |
| moved             | 0  | 0  | 0  | 0  | 0     |
| code_reused       | 0  | 0  | 0  | 0  | 0     |
| retitled          | 10 | 1  | 49 | 26 | 86    |
| retitled_cosmetic | 2  | 0  | 0  | 0  | 2     |
+-------------------+----+----+----+----+-------+
```

`--details` lists every change with its old and new code and title:

```
removed FA3Z "Unspecified joint disorders and deformities of limbs" -> (none)
removed QA74 "Unspecified appropriateness of dosing or administration without injury or harm" -> (none)
retitled ME66 "Miscellaneous non-specific skin-related symptoms and signs" -> ME66 "Miscellaneous non-specific skin-related symptoms or signs"
retitled QB95.8 "Tobacco rehabilitation" -> QB95.8 "Contact with health services for care involving use of tobacco rehabilitation"
retitled_cosmetic 6C4D.5 "Dissociative drug-induced psychotic disorder including Ketamine or PCP" -> 6C4D.5 "Dissociative drug-induced psychotic disorder including ketamine or PCP"
```

48 of the 86 retitles only change "and" to "or". They count as real
retitles, not cosmetic ones, because the meaning can change. The rest are
rewordings such as "Alcohol rehabilitation" becoming "Contact with health
services for care involving use of alcohol rehabilitation", or narrower
titles such as "Insufficient intake of food or water due to self neglect"
losing its last four words. A change of case or punctuation only, like
Ketamine to ketamine, is reported as cosmetic.

## Searching

```bash
curl "http://localhost:8000/api/search?q=back+pain+sciatica"
```

Parameters:

| Parameter | Default | Description |
| --- | --- | --- |
| `q` | required | Free text or a code (at least 2 characters) |
| `release` | latest imported | For example `2025-01` |
| `chapter` | all | `06`, `15`, `21` or `24` |
| `leaf_only` | `0` | Only the most specific codes |
| `primary_only` | `0` | Leave out codes that may not be used as a main diagnosis |
| `include_blocks` | `0` | Also return groupings that have no code |
| `limit` | `10` | Maximum 50 |

Each hit says how it matched (`matched_via`): `code`, `title`, `partial` (some
of the words) or `fuzzy` (spelling).

`score` means something different for each `matched_via`, so it is only
comparable within the same one:

| `matched_via` | `score` |
| --- | --- |
| `code` | 1 for an exact code, 0 for a code prefix |
| `title` | Number of search words found in the code's own title |
| `partial` | Number of search words that matched |
| `fuzzy` | Trigram word similarity, 0 to 1 |

Hits are sorted by `matched_via` first (in the order above), then by score.

Look up a single code with its parents, children and coding note:

```bash
curl "http://localhost:8000/api/codes/ME84.20"
```

The response has the same fields as a search hit (without `matched_via` and
`score`), plus `coding_note`, `ancestors` (chapter, blocks and parent
categories, root first) and `children`. Add `release=2025-01` to look a code
up in an older release.

Errors: `422` for invalid input, `404` for an unknown code or a release that
is not imported, `503` when no release has been imported yet.

### Example searches

Real results from release 2026-01. One hit in full:

```bash
curl "http://localhost:8000/api/search?q=back+pain+sciatica&limit=1"
```

```json
{
  "data": [
    {
      "code": "ME84.20",
      "block_id": null,
      "title": "Lumbago with sciatica",
      "class_kind": "category",
      "chapter": "21",
      "breadcrumb": [
        "Symptoms, signs or clinical findings, not elsewhere classified",
        "Symptoms, signs or clinical findings of the musculoskeletal system",
        "Symptoms or signs of the musculoskeletal system",
        "Spinal pain",
        "Low back pain"
      ],
      "is_leaf": true,
      "is_residual": false,
      "primary_coding_allowed": true,
      "matched_via": "title",
      "score": 1
    }
  ],
  "meta": {
    "release": "2026-01",
    "attribution": "ICD-11 for Mortality and Morbidity Statistics (ICD-11 MMS), World Health Organization (WHO), https://icd.who.int/. Licensed under CC BY-ND 3.0 IGO, https://creativecommons.org/licenses/by-nd/3.0/igo/."
  }
}
```

"Lumbago with sciatica" does not contain "back" or "pain". It is found
because its parent is "Low back pain"; parent titles are part of the search
but count less.

The top hits for a few more searches:

**A code:** `q=6A70`

| # | Code | Title | matched_via |
| --- | --- | --- | --- |
| 1 | 6A70 | Single episode depressive disorder | code (exact) |
| 2 | 6A70.0 | Single episode depressive disorder, mild | code (prefix) |
| 3 | 6A70.1 | Single episode depressive disorder, moderate, without psychotic symptoms | code (prefix) |

**Several words:** `q=back pain sciatica`

| # | Code | Title | matched_via |
| --- | --- | --- | --- |
| 1 | ME84.20 | Lumbago with sciatica | title |
| 2 | ME84.3 | Sciatica | partial |
| 3 | ME84.2 | Low back pain | partial |
| 4 | ME86.21 | Back syndrome with radiating pain | partial |

**A misspelling:** `q=depresion`

| # | Code | Title | matched_via |
| --- | --- | --- | --- |
| 1 | MB24.5 | Depressed mood | fuzzy |
| 2 | 6A71 | Recurrent depressive disorder | fuzzy |
| 3 | 6A70 | Single episode depressive disorder | fuzzy |

All fuzzy hits here have the same score (0.6), so the shorter title wins.

**Part of a word (typeahead):** `q=depr` gives the same codes as above, but
`matched_via` is `title`.

**One chapter:** `q=pain&chapter=15`

| # | Code | Title | matched_via |
| --- | --- | --- | --- |
| 1 | FB56.4 | Pain in limb | title |

Only one hit. Fuzzy matching only runs when nothing else matched, so short
words like "pain" are not padded out with "Knuckle pads".

**A lay term:** `q=tennis elbow`

| # | Code | Title | matched_via |
| --- | --- | --- | --- |
| 1 | FB55.0 | Medial epicondylitis of elbow | partial |
| 2 | FB55.1 | Lateral epicondylitis of elbow | partial |
| 3 | ME86.4 | Symptom or complaint of the elbow | partial |

FB55.1 is tennis elbow, but it is only found because "elbow" is in the
title. There are no synonyms yet, so "tennis" means nothing to the search.

## Tests

```bash
docker compose exec app php artisan test
```

Tests run against a separate PostgreSQL database (`icd_testing`) and never use
the network. The test database is created the first time the database volume
is created; if you have an older volume, run `docker compose down -v` once.

### Golden queries

A separate test measures search quality against the real 2026-01 release:
20 queries a nurse might type, each with the code it should find. It needs
the release zips in `storage/app/icd/` (they are there after the imports
above) and is left out of the normal test run:

```bash
docker compose exec app php artisan test --group=golden
```

It reports recall@5, the share of queries whose expected code is in the top
five. Current result: **17 of 17 expected hits found (100%)**. The queries
cover codes, code prefixes, everyday phrases, partial words and misspellings
such as "depresion", "sciatca" and "alzhiemer".

Three queries are known weak spots. They are reported but never fail the
test:

| Query | Should find | Result |
| --- | --- | --- |
| fibromialgia | MG30.01 Chronic widespread pain | Miss: no word in common with the title |
| anxeity | 6B00 Generalised anxiety disorder | Miss: swapped letters hurt trigram matching |
| frozen shoulder | FB53.0 Adhesive capsulitis of shoulder | Found at rank 4, but only through "shoulder" and a tie-break |

Lay terms like "fibromyalgia" and "frozen shoulder" need synonyms, which are
not in this version. Swapped letters need a typo tolerance that trigrams do
not give.

## Data and licence

ICD-11 data © World Health Organization, licensed under
[CC BY-ND 3.0 IGO](https://creativecommons.org/licenses/by-nd/3.0/igo/).
The release files are downloaded from WHO at import time and are not included
in this repository. Titles and codes are served unmodified.
