# Changelog

## Unreleased

- Add an optional Swoole HTTP gateway with process-isolated application requests, bounded concurrency, deadlines, memory/output limits and private upload transport.
- Add structured concurrent I/O task groups with keyed results and exception settlement; retain synchronous HTTP operation without Swoole.
- Add real-socket async, request isolation, upload, failure recovery and backpressure checks plus optional PHP 8.2/8.5 async CI.

## 2.2.0 — 2026-11-06

- Correct application termination and middleware response preparation, including HEAD.
- Support static route closures and unwind route groups after registration failures.
- Cache compiled dynamic routes and invalidate them when routes change.
- Isolate sequential request route, URL, PSR request and authentication state.
- Complete provider and console boot lifecycles and isolate facade roots.
- Return appropriate validation/authentication errors; hide production server-error details and invalid dotenv contents.
- Wire database events and configured logging channels correctly.
- Harden database testing cleanup and preserve simulated test identities.
- Add optional Redis/PSR integration diagnostics and executable SQLite, MariaDB and Redis coverage.

Named route parameters are encoded as single segments. See UPGRADE.md for migration details. PHP remains ^8.2. Concurrent worker safety and application-specific authentication policies require separate review.
