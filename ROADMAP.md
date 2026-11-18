# Roadmap

## Ideas
- Extra folders to measure (and rotate) configured in the settings
- Slack / Teams message formats for the webhook
- A «resolved» message when a condition clears
- Optional database table for the measurements (for hosts without persistent `storage/`)
- Per-site retention for multi-site setups
- Export of the history as CSV
- Widget for the Craft dashboard with the key figures
- An allow list of addresses for remote queries

## Done
- 1.0.0 – overview, opt-in retention rules, versions clearing, notifications, status endpoint, console, queue job
- 1.1.0 – icons on the key figure cards
- 1.2.0 – French and Italian
- 1.3.0 – actions on the cards, dialogs, remote queries, information tab, position in the sidebar
- 1.4.0 – cache figure, several measurements a day, remote answer with versions and updates
- 1.5.0 – remote queries hardened (key from a header only, HTTPS only, key kept out of the logs), deleting single
  measurements
- 1.6.0 – statistics (a chart per key figure), running status of actions, compressing backups, log files to keep,
  remote monitoring allowed per environment on the information tab
- 1.6.1 – compiled templates cleared after an update
- 1.6.2 – queue watch, deleting several rows at once, an overview that survives a failing part
