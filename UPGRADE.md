# Local 2.1 engineering changes

These changes are not a published release. Runtime PHP remains `^8.2`; Composer consumers still use `nour-api/framework ^2.1`.

- `Application::run()` sends the response and invokes application termination correctly. Global middleware short-circuits are prepared as HTTP responses, including HEAD responses.
- Route groups unwind after registration exceptions. Static closures are accepted. The FastRoute dispatcher is cached per router revision; adding/replacing routes or replacing the router rebuilds it. `setDispatcher()` retains its explicit override semantics.
- Request route metadata and URL-derived host/scheme caches reset for sequential requests. Ordinary Symfony/Illuminate requests are adapted to Nour requests; both Illuminate and Nour request injection resolve the current request. Resolved PSR requests are forgotten between dispatches.
- The Nour authentication manager retains guards and clears cached identities via `forgetUser()` before subsequent requests. Custom guard state requires separate review. Use a fresh application for concurrent requests.
- Named route parameter values are encoded as single URL segments. Supply raw values and remove pre-encoding; review parameters that previously contained intentional slashes.
- Validation exceptions without a custom response return JSON 422 with `message` and `errors`. Authentication exceptions return JSON 401. Production HTTP 5xx JSON errors hide exception messages. Review clients that assumed a 500 response for these cases.
- Invalid environment files report a generic syntax error without printing configuration contents. Uncaught CLI exceptions return a failing process exit code.
- The human-readable application version identifies Nour-API/Illuminate 12 instead of Lumen/Illuminate 11. Do not parse this string as a stable machine API.
- Optional Redis services now register lazily when `illuminate/redis:^12.21` is installed; otherwise resolution reports an actionable exception. PSR response conversion also explains the missing bridge dependency. The stale binder for the removed Illuminate Eloquent Factory class is removed.
- The testing base restores PHP process error/exception handlers after each test. Tests constructing Application directly can use `Testing\Concerns\RestoresPhpHandlers`.

The skeleton's token authentication remains an example requiring an application schema and a production token policy. Sessions, CSRF and CORS policies are application configuration responsibilities. No new session, authentication issuance, plugin manager or concurrency runtime is introduced.

## Continued integration hardening

- Programmatic console `bootstrap()`, `call()` and command discovery boot registered service providers once, matching the CLI entry point.
- Providers registered during application boot are also booted before completion. Reentrant boot calls are guarded, and successful providers are not repeated when initial boot is retried after another provider fails.
- Facade activation clears cached roots when switching applications. Custom aliases may be added after defaults were already registered; existing class aliases are preserved.
- `DatabaseMigrations` fails the test on nonzero migration/rollback exit codes. `DatabaseTransactions` restores connections if setup partially fails, returns nested transactions to their previous level, and attempts cleanup on every connection before reporting a cleanup failure.
- Testing `actingAs()`/`be()` identities persist across that test's requests and reset on application refresh. This test-only behavior does not disable runtime request identity isolation.
- Test teardown attempts every registered cleanup and flushes the application even if a cleanup callback fails; it then reports the first failure.
- The development suite checks actual PDO SQLite driver availability for executable database coverage, without requiring a particular extension provider. Production requirements and Composer consumers remain unchanged.

## CI compatibility corrections

- Resolving the database component also resolves its event dispatcher before a connection is created. Query and transaction events therefore work before application boot, without depending on other services having already initialized events.
- The installer permission regression check refreshes PHP stat metadata before verifying the private environment file mode. The required permission remains `0600`.
- Logging binds Illuminate's default context processor when that integration is available, preserving explicit application bindings. Configured channels now avoid an unintended emergency-logger fallback.
