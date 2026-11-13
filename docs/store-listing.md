# Plugin Store listing

What goes into the form in the Craft Console (*Plugin Store → Plugins → Add a plugin*). The Console reads most of it
from `composer.json`; descriptions and URLs are not synced again afterwards, so change them in the Console too.

| Field | Value |
| --- | --- |
| Repository | `https://github.com/pixelwerft/data-keeper` |
| Name | Data Keeper |
| Package name | `pixelwerft/data-keeper` |
| Handle | `data-keeper` |
| License | MIT |
| Price | Free |
| Documentation URL | `https://github.com/pixelwerft/data-keeper/blob/main/README.md` |
| Changelog | `CHANGELOG.md` |
| Icon | `src/icon.svg` |
| Screenshots | `docs/screenshots/overview.png`, `cleanup.png`, `versions.png`, `settings.png` |
| Keywords | housekeeping, revisions, database, cleanup, retention, data minimisation, gdpr, monitoring |
| Categories | pick what fits best in the form, e.g. utilities, performance, developer tools |

## Short description

See how much your site stores and how fast it grows – and keep it in check with opt-in retention cleanup.

## Long description

Data Keeper shows how much your Craft site stores and how fast it grows: database, saved versions, change records,
deleted elements, backups, logs and files. Retention rules remove what is too old – but only after you switch them on
and confirm a run.

Store only what you need. The less a site keeps, the less can leak, the smaller backups get, and the shorter the list
of retention periods in your privacy policy.

### What you get

- **Overview** – a card per key figure with a level (*OK*, *Keep an eye on it*, *Act now*) and the change since the
  last measurement, the largest database tables, the folders and the course over the months.
- **Measuring on request** – from the control panel, the console or the queue. The measurements live in one small file
  in `storage/`, not in your database.
- **Retention cleanup, opt-in** – rules for saved versions, database backups, log files, Craft’s change records and any
  other table with a date column. Every rule is off by default.
- **See before you delete** – the *Cleanup* page shows what every rule would remove. Removing needs a confirmation,
  runs in the queue and is refused if the numbers changed since you looked.
- **Clear saved versions per entry** – keep the newest N, optionally the very first one, optionally only by age.
- **Actions on the cards** – clear the change records, empty the trash, clear the logs, delete single backup files,
  optimize and check the database tables. Each says what it would do and only runs after you confirm.
- **Remote queries** – read the figures as JSON from outside, for example for a dashboard that watches several sites.
  Off by default; access needs the key of the site.
- **Notifications** – as a notice in the control panel, by e-mail or webhook, and at a status endpoint for monitoring
  tools.
- **For your privacy policy** – the retention periods of all active rules, ready to copy.
- Console commands, a queue job, permissions. Interface in English, German, French and Italian.

### Requirements

Craft CMS 5.0 or later, PHP 8.2 or later, MySQL, MariaDB or PostgreSQL.

## Before the plugin shows up in the store

1. Push the code to the public GitHub repository (the Console cannot load an empty repository).
2. Add the plugin in the Console and submit it for approval.
3. Tag the release (`v1.0.0`) and push the tag; the heading in `CHANGELOG.md` must be `## 1.0.0 - YYYY-MM-DD` with
   the day of the release.
4. Optional: register `pixelwerft/data-keeper` on Packagist so `composer require` works outside the store.
