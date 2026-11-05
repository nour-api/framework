<?php

/*
|--------------------------------------------------------------------------
| Register The Composer Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader
| for our application. We just need to utilize it! We'll require it
| into the script here so that we do not have to worry about the
| loading of any our classes "manually". Feels great to relax.
|
*/

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Set The Default Timezone
|--------------------------------------------------------------------------
|
| Here we will set the default timezone for PHP. PHP is notoriously mean
| if the timezone is not explicitly set. This will be used by each of
| the PHP date and date-time functions throughout the application.
|
*/

date_default_timezone_set('UTC');

Carbon\Carbon::setTestNow(Carbon\Carbon::now());

// Check the PDO capability rather than an extension name: alternative PHP
// extensions may register a working SQLite driver without ext-pdo_sqlite.
if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('Database integration tests require a PDO SQLite driver (usually ext-pdo_sqlite).');
}
