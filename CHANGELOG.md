# Changelog

All notable changes follow [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and semantic versioning.

## [Unreleased]

## [1.1.0] - 2026-08-21

### Added

- Registered Site Tracker with the shared ViewMend product Dashboard, including connection and delivery health metrics.

### Changed

- Moved the backend module under **ViewMend → Site Tracker** while preserving its stable module identifier and access rules.
- Added `viewmend/typo3-core` as the shared Dashboard and navigation dependency.

## [1.0.1] - 2026-08-18

### Changed

- Replaced the generic extension icon with the official ViewMend brand mark in the TYPO3 backend and TER listing.

## [1.0.0] - 2026-08-18

### Added

- Local-first, duplicate-safe Site Tracker delivery queue with leases, bounded retry/backoff, `Retry-After`, retention and safe recovery.
- Confirmed public page/content publish, update, unpublish and delete reconciliation for workspaces, translations, multisite binding and scheduled visibility.
- Optional full-cache-flush events and explicit stable-ID deployment markers.
- Native TYPO3 backend module for readiness, connection, event rules and delivery Activity.
- Environment or authenticated encrypted-database token sources with log/history redaction.
- TYPO3 Scheduler/Symfony commands and System Reports diagnostics.
- TYPO3 13.4/14.3, PHP 8.3–8.5, MariaDB, SDK contract, Docker and CI verification assets.
