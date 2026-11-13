# Release Notes for Data Keeper

All notable changes to this project are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added
- An icon on every key figure card of the overview.

## 1.0.0 - 2026-11-13

First public release.

### Added
- Overview of how much the site stores and how fast it grows: database size and its largest tables, saved versions
  (and the entries with the most), change records, deleted elements, backups, logs, temporary files, image transforms
  and local asset volumes – each key figure with a level and its change since the measurement before.
- Measuring only on request (control panel, console, queue); the measurements are kept in a small file in
  `storage/data-keeper/`, not in the database.
- Retention rules for saved versions, database backups, log files, change records and any other table with a date
  column. Every rule is off by default; the *Cleanup* page shows what each would remove before anything is deleted, and
  removing needs a confirmation and runs in the queue.
- Saved versions can be removed by age (older than 1 or 2 years, freely settable) as well as by count; the newest N versions and optionally the very first one always stay. The overview shows how many versions are older than a year and two years.
- Clear the saved versions of a single entry from the list of entries with the most versions (needs the right to view and save that entry).
- Notifications for four topics (key figure too big, no measurement for a while, cleanup finished or failed, old data
  waiting) as a control panel notice, as e-mail and webhook messages (signed with an optional secret), and at a token
  protected status endpoint for monitoring tools.
- A list of the retention periods of all active rules for the privacy policy.
- Console commands `data-keeper/measure`, `cleanup`, `cleanup/list`, `run` and `status`; every cleanup on the
  console is a dry run unless `--apply` is given.
- Optional queue job `RunJob` (cleanup and measure).
- Permissions «Measure the site» and «See what a cleanup would remove, run it and clear saved versions».
- Settings page and `config/data-keeper.php` for per-environment overrides.
- English and German (`de`, `de-CH`) translations.
