# Release Notes for Data Keeper

All notable changes to this project are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.4.0 - 2026-11-13

### Added
- A key figure «Cache» on the overview: the size of Craft’s data cache (when it is kept in files), the compiled
  templates and the compiled classes, with a button that clears them. It shows «Not measured yet» until the next
  measurement.
- Every measurement of the last 31 days is kept and listed under «Last measurements» on the overview, also several on
  one day. Before, a second measurement on the same day took the place of the first. The course still shows the last
  measurement of each month.

### Changed
- Remote queries answer with a new structure (`schema` 2): `lastMeasurement` with the key figures of the last
  measurement, and `craftCms` with the versions of Craft, PHP, the database and the plugins and the updates that are
  available. What the answer held before is still there with `?details=1`, under `details`.
- The address for remote queries always answers with JSON, also when something is wrong: 403 `disabled` while «Allow
  remote queries» is switched off (before: a 404 page of the site), 401 `unauthorized` without the right key.

## 1.3.0 - 2026-11-13

### Added
- Sums below the database tables (the ones listed and, from the next measurement on, all tables) and below the file
  storage on the overview.
- Buttons on the key figure cards: clear the change records, empty the trash, clear the log files (can be switched off
  in the settings), list the backup files and delete single ones, optimize the database tables and, on MySQL and
  MariaDB, check them. Each says what it would do first and only runs after a confirmation. On the console:
  `data-keeper/maintenance`.
- Remote queries: with «Allow remote queries» switched on, `/actions/data-keeper/remote` answers with the stored
  figures as JSON for whoever sends the key of the site. The key is generated once, kept in `storage/`, and can be
  copied and replaced by admins on the new «Information» tab.
- An «Information» tab on the overview with the versions of Craft, PHP, the database and the plugin.
- A setting for the position of the section in the sidebar.
- «Remember these options as the default» when clearing the saved versions of an entry.

### Changed
- Clearing saved versions, the actions of the cards and the backup files open in a dialog on the overview instead of
  on a page of their own.
- The section sits second from the bottom of the sidebar by default.
- After saved versions were cleared, the overview shows the new numbers at once instead of the ones of the last
  measurement.
- The German interface calls the section «Data Keeper» like the other languages, with the line «Übersicht
  Datenhaushalt» below the title.
- «Largest tables» is now «Database tables (by size)», «Folders» is «File storage».

### Fixed
- Clearing the saved versions of an entry missed versions that only exist in another site than the entry’s first one.

## 1.2.1 - 2026-11-13

### Changed
- The Data Keeper section now sits at the bottom of the control panel sidebar, below Plugin Store, next to the other
  housekeeping tools – directly before User Audit when it is installed.

## 1.2.0 - 2026-11-13

### Added
- French and Italian translations.

## 1.1.0 - 2026-11-13

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
