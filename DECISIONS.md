# Decisions

Why I built it the way I did, what I chose not to do, and what I would do
next. What the WHO files actually contain is in
[notes/data-findings.md](notes/data-findings.md).

## 1. PostgreSQL

I wanted stemming, weighted full-text search and typo matching, and Postgres
has all of it built in. `pg_trgm` handles the typos.

I looked at SQLite with FTS5 since it's the easiest to run. It has stemming
and ranking, but no typo matching out of the box. Its trigram tokenizer
matches parts of words, not similar words, so I would have had to build what
`pg_trgm` already does.

A search engine like Typesense or Meilisearch felt like too much for about
3 500 rows per release. It's one more service and one more index to keep in
sync. I would look at it again if we get thousands of synonyms or several
languages. The search sits behind one class, so the API wouldn't change.

## 2. The release files can't be trusted to look the same

Both releases have the same readme, but the files differ. 2026-01 has two
extra columns (`CodingNote` and `Parent`), the header names don't match the
readme, and some coding notes have line breaks in them that split one record
over 2 to 11 lines.

So I map columns by header name instead of position, glue records back
together until the field count is right, and check that the version in the
header matches the release I asked for. A missing column, a wrong version or
a broken record stops the import instead of loading bad data. An extra column
I don't know is noted and ignored.

I didn't want to hardcode column positions or write one parser per release.

## 3. One full copy per release

Every release is stored in full. Codes can move between entities, and
residual categories have no foundation URI, so I match entities across
releases by the linearization URI. It's the only id that is unique on every
row.

Slowly changing dimensions would save space, but space isn't a problem here,
and it would make the diff and every query harder.

## 4. The tree comes from the dashes in the title

The file is ordered depth first, and each level adds `- ` in front of the
title. A simple stack gives every row its parent.

`DepthInKind` looked useful but starts over for each class kind.
`Grouping1-5` only lists blocks, and about 70% of parent links are a category
under another category. I still check the tree against `Grouping`, and
against `Parent` in 2026-01 (2025-01 doesn't have it). A mismatch stops the
import. On the real data there were none.

## 5. The import can be run again

I store the sha256 of the data file inside the zip. Same data again means
nothing happens, even if the zip itself was repacked. The
import runs in one transaction that replaces the release's rows, so if it
fails halfway the old data is still there. If the file for a version I
already have has changed, the import refuses unless you add `--force`, and
then it shows what changed.

Upserting row by row wouldn't remove rows that are gone from the new file.

## 6. Diff in PHP after the import

After an import I compare the release with the closest older one, and
recompute the next newer one too. That way it doesn't matter which order you
import them in. The comparison is a plain function over two maps keyed by
URI, which made it easy to test each change type on its own.

From 2025-01 to 2026-01, in our four chapters: 4 removed, 86 retitled and 2
cosmetic retitles. Nothing was added, recoded, moved or reused.

Only changes in case or punctuation count as cosmetic. 48 of the 86 retitles
just change "and" to "or", and I still count those as real changes since the
meaning can change. Some others clearly change the meaning, like
"Insufficient intake of food or water due to self neglect" losing "due to
self neglect". I'd rather report too much here than hide something.

I could have done the diff as a full outer join in SQL. It would scale
better, but then the rules would live in two places.

## 7. Search

Search goes in steps: code first, then titles with all the words, then
titles with some of the words, then typo matching. Each hit says which step
found it.

The titles of a code's parents are part of what's searched, with lower
weight. Child titles often leave out what the parent says. "Lumbago with
sciatica" sits under "Low back pain", and without the parent "back pain
sciatica" finds nothing.

The last word also matches as the start of a word, as typed, so a half word
like "depr" already finds "depressive" while you type.

For ranking I first used `ts_rank`, but it counts repeated words and word
positions, and children and residual codes ended up above their parents. Now
an all-words hit scores the number of search words in the code's own title,
and a some-words hit scores how many words matched. Ties go to codes that can be used as a main diagnosis, then specific
over "other" and "unspecified", then shorter title, then code.

Typo matching only runs when nothing else matched. Before I changed that, a
search for "pain" in chapter 15 got padded with "Knuckle pads". I set the
threshold to 0.4 after trying ten misspellings at different values. 0.3 just
added noise and 0.5 lost "alzhiemer".

By default search only returns categories, since those are what you can
record. All chapters are ranked the same, because putting one chapter first
would be a clinical call. The 212 codes that can't be used as a main
diagnosis are flagged.

## 8. No synonyms yet, English only

Search finds everyday words when they appear in WHO's titles, like "back
pain" or "panic". "Tennis elbow" and "frozen shoulder" are found, but only
through "elbow" and "shoulder" and a bit of luck in the ordering. Words that
never appear in a title, like "fibromyalgia" (Chronic widespread pain), are
not found.

A synonym list would fix that. I didn't build one this time. If it's added,
it has to be separate data, since the licence (CC BY-ND) doesn't allow
changing WHO's titles.

The files are in English and the brief doesn't mention Swedish, so I didn't
assume it.

## 9. Tests

I focused tests on the places where a bug would actually hurt a nurse:
parsing both formats, bad files, running the import twice, import order,
every diff type, ranking and API validation. Tests run on PostgreSQL, since
SQLite would be testing a different search. The WHO download is faked.

There's also a golden set of 20 searches run against the real 2026-01 data,
with codes, everyday phrases, half words and misspellings. All 17 expected
hits are in the top 5. Three weak spots are reported but don't fail the test.
"fibromialgia" and "anxeity" miss, and "frozen shoulder" is only found by
luck. It needs the real files, so it runs separately with
`--group=golden`.

## 10. Docker Compose

The only thing you need is Docker. The app container runs as your own user so
the files it creates aren't owned by root. On first start it installs
dependencies, creates the app key and runs migrations, and a healthcheck makes
`docker compose up --wait` return once the API answers.

Cloning into an empty folder caught a problem that my tests didn't. The first
README version ran the next command before Composer had finished. It worked
on my machine because `vendor/` was already there.

I didn't use Laravel Sail. It needs Composer before the first start.

## 11. Data first, then spec, then code

I read the WHO files before writing the spec, and wrote the spec before
writing code. Several things I assumed about the files turned out to be
wrong, so that order saved me from redoing work. Claude Code wrote most of
the code from the spec. I made the calls on ranking and scope. The build
itself took a bit over three hours, plus some preparation earlier in the
week.

## With another day

1. Synonyms for lay terms like "tennis elbow" and "frozen shoulder", kept
   in their own table so WHO's titles stay unchanged.
2. Better typeahead. A half typed word like "depressi" currently falls back
   to typo matching. Matching it against an unstemmed copy of the titles
   would fix that.
3. More golden queries, ideally from searches nurses actually make, and a
   CI job that fails if recall drops below a set level.
