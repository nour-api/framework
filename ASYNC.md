# Isolated async runtime (development)

This is development functionality on `dev`, after release 2.2.0. It is not part of the published 2.2.0 package. PHP-FPM, Apache and the existing `public/index.php` / `$app->run()` entry point continue to work without Swoole. No minimum PHP version or mandatory dependency changed.

## Serve an application

The optional runtime requires Linux CLI PHP 8.2+, Swoole 6.x and `proc_open`. Install Swoole in the CLI environment; the package does not install system extensions. From a project consuming this framework checkout:

```sh
vendor/bin/nour-serve --host=127.0.0.1 --port=8000
# Existing mode remains available:
php -S localhost:8000 -t public
```

For a framework checkout, the equivalent command is:

```sh
php /path/to/framework/bin/nour-serve --bootstrap=/path/to/project/bootstrap/app.php
```

The project must resolve this development framework through Composer to use its async APIs. A published 2.2.0 installation does not contain these classes. Use the application repository's existing CI Composer overlay for local integration; do not put local paths in a release manifest.

The bootstrap must return a Nour-API `Application`. Existing controllers, routing, container bindings and middleware remain synchronous-looking PHP. The gateway handles concurrent sockets with Swoole coroutines. Each accepted request executes in a **fresh PHP process**; Swoole hooks make supported I/O inside that process cooperative. Request, authentication, facades, configuration, PHP handlers and application statics are not shared across requests. Terminable middleware and application callbacks run before the worker exits.

The process boundary is intentional: Illuminate's static container/facade roots and arbitrary application statics are not coroutine-local. Sharing an ordinary application instance between overlapping requests would leak state. Process startup and application bootstrap cost are paid on every request; this is not an in-memory, boot-once worker engine. Increasing process concurrency does not accelerate CPU-bound code. Use measured resource limits appropriate to the workload.

## Concurrent I/O within one request

```php
use NourApi\Framework\Runtime\Concurrent;

$values = Concurrent::run([
    'one' => static fn () => file_get_contents('https://service-one.example/status'),
    'two' => static fn () => file_get_contents('https://service-two.example/status'),
], concurrency: 2);
```

Supply independent tasks and configure timeouts on their I/O clients. `run()` returns keyed results in input order after all tasks settle, or rethrows the first observed task exception after joining the remaining tasks. Nested groups, null/false values and bounded task concurrency are supported. This is structured fork/join, not a background queue or detached task API. It does not make CPU work parallel.

The isolated runtime enables Swoole hooks in its disposable application process. When using `Concurrent` directly from a CLI script, explicitly enable the hooks you require before calling it (`Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL)`). Outside a coroutine it creates a scope; inside one it uses the existing scope. It never silently runs concurrent tasks serially when Swoole is missing. Existing synchronous routes remain unaffected. Hook coverage depends on the extension and I/O driver; an unhooked blocking operation still blocks its process. See the [official Swoole runtime API](https://www.php.net/manual/en/swoole-runtime.enable-coroutine.php).

Tasks within **one request** share that request's application. Do not concurrently mutate global state, container bindings, facades, session state, request headers or shared service instances. Allocate a separate client/connection for each independent I/O operation. Never share a PDO transaction between tasks. CPU tasks and durable jobs belong in explicitly managed processes or the existing queue system.

## Limits and HTTP behavior

Options shown by `vendor/bin/nour-serve --help` include:

| Option | Default | Meaning |
| --- | --- | --- |
| `--workers` | 1 | HTTP gateway processes |
| `--concurrency` | 16 | Active application processes per gateway worker |
| `--timeout` | 30 | Deadline in seconds, including bootstrap and termination |
| `--memory-limit-mb` | 128 | PHP memory limit per application process |
| `--max-request-bytes` | 2097152 | Swoole HTTP package size limit |
| `--max-response-bytes` | 16777216 | Encoded IPC response limit, including base64 expansion |

At capacity, the gateway returns 503 with `Retry-After: 1`. A deadline returns 504 and kills/reaps the application process. A crashed process, invalid result or oversized response returns a generic 502. Error payloads never include captured stderr, stack traces or bootstrap secrets. Application-level exception rendering remains the existing framework handler. Abrupt process death cannot guarantee application termination callbacks; uploads in gateway-owned temporary storage are cleaned by the gateway. Use durable queues for work that must survive request failure.

Controlled gateway worker exit/stop, including SIGTERM shutdown, cancels and reaps its active application processes before removing their private request directories. Hidden files and nested directories are included; symbolic links are unlinked without following their targets. A forcibly killed gateway (SIGKILL) cannot execute these callbacks: production supervision must manage the whole process group. Application work requiring guaranteed completion belongs in a durable queue.

Private IPC preserves arbitrary PHP string bytes in request form keys/values, headers, uploads and response bodies. Its JSON envelope encodes byte strings and keys explicitly, accepts only arrays/scalars/null, and rejects malformed/ambiguous envelopes without instantiating objects. Response size limits apply to the encoded envelope. HEAD preserves an application's explicit representation length, including across keep-alive requests; gateway errors also suppress HEAD bodies. Empty 204/304 responses suppress automatic content-length/type defaults. Informational 1xx statuses cannot serve as a final buffered response and produce a generic 502. These framing rules follow [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110.html).

Query strings, JSON/raw bodies, forms, cookies, uploaded files, status codes, multiple response cookies, HEAD, terminable middleware and application termination are supported. Uploads cross a private IPC boundary into private temporary files; framework `UploadedFile` objects support normal validation/move APIs. Native `is_uploaded_file()` does not recognize IPC files. Return Symfony/framework responses and cookies; native CLI `header()`, `setcookie()`, output-buffer manipulation and `php://input` are not a replacement for the request/response APIs in this mode. Use `$request->getContent()` for raw bytes.

`StreamedResponse` and `BinaryFileResponse` output is **buffered** within the response limit. Live SSE, WebSockets, HTTP upgrade, HTTP trailers and unbounded downloads are not provided. Static serving is disabled; put a reverse proxy/static server in front of the gateway. It binds loopback by default and does not terminate TLS. Swoole owns hop-by-hop headers and message framing. The gateway does not trust forwarded proxy headers automatically.

## Verify

```sh
composer --no-plugins test -- --fail-on-risky --fail-on-warning
php tests/integration/concurrent.php
python3 tests/integration/async.py
python3 tests/integration/shutdown.py
```

The explicit integration checks require Linux, Swoole, POSIX functions, Python 3 and permission to bind loopback ports/start child processes. The shutdown check uses `/proc` and enables subreaper status only in its own Python process so a failing regression cannot abandon its own descendants. Checks use real sockets and PHP workers and compare sequential versus overlapping HTTP requests. They verify bounded I/O task overlap, key ordering, exception settlement, request/facade isolation, binary form/header transport, JSON/forms/uploads, cookies/binary data, buffered output, raw HEAD framing, keep-alive, deadlines, nested upload cleanup, process failure, saturation, SIGTERM cleanup and recovery. Standard PHP-only CI remains separate; an optional Swoole job covers PHP 8.2 and 8.5.
