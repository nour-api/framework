# Database and optional service integration checks

The normal PHPUnit suite includes real in-memory SQLite CRUD, SQL parameter binding, database-backed validation, migrations/rollback, nested transactions and cleanup failure tests. The development test suite requires an available PDO SQLite driver, normally supplied by `ext-pdo_sqlite`. The test bootstrap checks PDO driver availability rather than the extension name; the runtime package does not require SQLite.

For optional Redis and PSR-7 integration, create a separate temporary Composer project. Replace `/absolute/path/to/framework` with the local checkout path:

```json
{
  "name": "nour-api-local/integration-checks",
  "require": {
    "nour-api/framework": "2.2.0",
    "illuminate/redis": "^12.21",
    "nyholm/psr7": "^1.8",
    "symfony/psr-http-message-bridge": "^7.3"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.5 || ^12.3",
    "mockery/mockery": "^1.6"
  },
  "repositories": [{
    "type": "path",
    "url": "/absolute/path/to/framework",
    "options": {
      "symlink": true,
      "versions": {"nour-api/framework": "2.2.0"}
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

For a real MySQL-compatible engine, run:

```sh
php tests/integration/mariadb.php
```

This explicit Unix check requires `mariadbd`, `mariadb-install-db`, PDO MySQL and POSIX functions. It initializes its own private temporary datadir with Unix-socket account authentication, disables TCP networking, and tests Eloquent CRUD, SQL parameter binding, database-backed uniqueness validation, transaction rollback and Artisan migration/rollback. It stops its own server and deletes only its own datadir afterward. No installed application database or existing database server is used. It does not install packages, alter system services or disable database authentication.
