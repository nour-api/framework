# Database and optional service integration checks

The normal PHPUnit suite includes real in-memory SQLite CRUD, SQL parameter binding, database-backed validation, migrations/rollback, nested transactions and cleanup failure tests. Development installation requires `ext-pdo_sqlite`; the runtime package does not require SQLite.

For optional Redis and PSR-7 integration, create a separate temporary Composer project. Replace `/absolute/path/to/framework` with the local checkout path:

```json
{
  "name": "nour-api-local/integration-checks",
  "require": {
    "nour-api/framework": "2.1.1",
    "illuminate/redis": "^12.21",
    "nyholm/psr7": "^1.8",
    "symfony/psr-http-message-bridge": "^7.3"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.5 || ^12.3",
    "mockery/mockery": "^1.6",
    "ext-pdo_sqlite": "*"
  },
  "repositories": [{
    "type": "path",
    "url": "/absolute/path/to/framework",
    "options": {
      "symlink": true,
      "versions": {"nour-api/framework": "2.1.1"}
    }
  }]
}
```

From that temporary project:

```sh
composer install --no-plugins --no-scripts
php vendor/bin/phpunit -c /absolute/path/to/framework/phpunit.xml.dist --bootstrap vendor/autoload.php --fail-on-risky --fail-on-warning
php /absolute/path/to/framework/tests/integration/redis.php vendor/autoload.php
```

The version alias selects local source for testing; it does not publish a release. Run the framework's normal development installation first, since subprocess bootstrap tests intentionally also check its ordinary autoloader.

The explicit Redis script requires Linux/Unix, `redis-server`, and `phpredis`. It starts its own non-persistent Redis process with TCP disabled and a private temporary Unix socket. It verifies cache read/write, atomic add, lock ownership, queue push/pop, job execution and acknowledgement. It stops its own process and removes its own temporary files, including on failure. It never connects to the application's configured Redis server.

The PHPUnit optional checks verify Redis container resolution and PSR response/request conversion, including request refresh between HTTP dispatches. Without optional packages the ordinary suite checks actionable dependency errors. The optional CI job installs those packages only in its disposable checkout; the real Redis process script remains an explicit local check.
