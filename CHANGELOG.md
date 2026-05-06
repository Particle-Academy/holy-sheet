# Changelog

All notable changes to `particle-academy/holy-sheet` will be documented in this file.

## [1.0.1] — 2026-05-06

Adapter cleanup + comprehensive docs + runnable demo. No breaking changes if you only used the package's facade or `Agent::*` API.

### Changed
- **Removed** `HolySheet\Laravel\Http\HolySheetController` — Holy Sheet no longer ships an HTTP layer. Apps that need an Export endpoint write their own controller around the facade. The recommended shape is documented in `docs/LaravelAdapter.md` and `docs/Recipes.md` (recipe 9).
- **Hardened framework-agnostic story** — `composer.json` `require` is now `php ^8.2 + ext-zip` only. Dropped redundant `illuminate/support` from `require-dev` (Orchestra Testbench pulls it). The Laravel adapter is fully opt-in: classes under `HolySheet\Laravel\*` only load when invoked.
- **Facade exposes the full Agent surface** — `validate()`, `write()`, `toBytes()`, `toolDefinition()`, `describe()`, `getVersion()` all reachable through `HolySheet\Laravel\Facades\HolySheet`. The singleton bound by the service provider is what your pipelines (queue jobs, listeners, controllers) see.

### Added
- `docs/Schema.md` — full schema reference. Every field, every type, every option.
- `docs/Recipes.md` — 10 end-to-end patterns (sales reports, multi-sheet, cross-sheet refs, dates, highlights, frozen panes, merged titles, query exports, HTTP controller, queue jobs).
- `docs/LaravelAdapter.md` — facade reference, service provider override pattern, artisan command, recommended HTTP controller shape.
- `examples/sales-report.php` — runnable 3-sheet demo touching every feature. `php examples/sales-report.php /tmp/sales.xlsx`.
- `examples/README.md` — walkthrough of what the demo demonstrates and how to verify your install.

### Tests
- Renamed the static-write test to `writeFile` (the static convenience matching the original 0.2.0 signature).
- New: `it('the HolySheet singleton mirrors Agent for facade/DI use')` exercises every facade method against a fresh instance.
- New: `tests/Laravel/FacadeTest.php` — every facade method via the real Laravel container/facade chain. Confirms singleton resolution by both class and `'holy-sheet'` alias.
- Removed: controller test (no controller in package anymore).
- 33 Pest tests passing.

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
