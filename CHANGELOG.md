# Changelog

All notable changes to `particle-academy/holy-sheet` will be documented in this file.

## [Unreleased]

### Added
- Initial package scaffold:
  - `composer.json` with PHP 8.2+ floor and Laravel 10–13 dev/integration support
  - `HolySheet\HolySheet` core class (framework-agnostic)
  - `HolySheet\Laravel\HolySheetServiceProvider` (auto-discovered)
  - `HolySheet\Laravel\Facades\HolySheet` facade
  - `config/holy-sheet.php` with publishable defaults
  - Pest test harness — `tests/Unit/` for pure PHP, `tests/Laravel/` for service-provider integration via Orchestra Testbench
  - GitHub Actions matrix CI (PHP 8.2/8.3/8.4 × Laravel 10/11/12)
  - README + CHANGELOG
