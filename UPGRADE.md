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
