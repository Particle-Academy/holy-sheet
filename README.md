# Holy Sheet

**Standalone spreadsheet writing tool for agentic document creation.**

Framework-agnostic PHP package for writing spreadsheets (xlsx, csv, ods, tsv) without leaning on heavy third-party libraries. Designed for agentic flows: ergonomic schemas an LLM can author directly, deterministic output, and a Laravel-friendly service provider for apps that want it. Compatible with PHP 8.2+ and Laravel 10–13.

## Status

**Scaffold (v0.1.0-dev).** Package metadata + Laravel auto-discovery + test harness + CI matrix are in place. The actual writing API lands in upcoming commits.

## Installation

```bash
composer require particle-academy/holy-sheet
```

The package will auto-discover the Laravel service provider on Laravel 10–13. On non-Laravel projects, ignore the `HolySheet\Laravel\*` namespace entirely — the core `HolySheet\HolySheet` class is fully usable on its own.

## Why this exists

Existing PHP spreadsheet libraries are either:

- Heavy and slow (PhpSpreadsheet pulls dozens of transitive deps and chokes on large sheets), or
- Tightly coupled to a single output format

Agentic flows need something different: a small, deterministic API where an LLM can describe the sheet as data (rows, columns, types, formats) and the package writes the file in one pass — no per-cell ceremony, no global state, no surprise dependencies.

Holy Sheet is that small library.

## Compatibility

- **PHP**: 8.2+
- **Laravel**: 10.x, 11.x, 12.x, 13.x (optional integration via service provider)
- **Frameworks**: any PHP 8.2+ project. Symfony, Laminas, plain PHP, CLI scripts — no Laravel required.
- **Dependencies**: stays minimal. Production code aims for zero third-party runtime deps; `ext-zip` recommended for native xlsx writing.

## Documentation

Coming with the writing API. For now this README marks the package as registered + bootable.

## License

MIT
