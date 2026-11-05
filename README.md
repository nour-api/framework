# Nour-Api Framework (Kernel)

#
[![Build Status](https://github.com/nour-api/framework/workflows/tests/badge.svg)](https://github.com/nour-api/framework/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/nour-api/framework)](https://packagist.org/packages/nour-api/framework)
[![Latest Stable Version](https://img.shields.io/packagist/v/nour-api/framework)](https://packagist.org/packages/nour-api/framework)
[![License](https://img.shields.io/packagist/l/nour-api/framework)](https://packagist.org/packages/nour-api/framework)

> **Note:** This repository contains the core code of the Nour-API framework. If you want to build an application using Nour-API, visit the main [Nour-API repository](https://github.com/nour-api/nour-api).

## Nour-API PHP Framework

Nour-API is a stunningly fast PHP micro-framework for building web applications with expressive, elegant syntax. We believe development must be an enjoyable, creative experience to be truly fulfilling. Nour-API attempts to take the pain out of development by easing common tasks used in the majority of web projects, such as routing, database abstraction, queueing, and caching.

## Official Documentation



## Contributing



## Security Vulnerabilities



## License

Nour-API is open-sourced software licensed under the [MIT license](LICENSE.md).

## Development and verification

The kernel uses Illuminate 12 components, Symfony HTTP responses and FastRoute. Runtime PHP remains `^8.2`. Development PHPUnit can resolve version 11.5 on PHP 8.2 and newer versions on compatible PHP runtimes.

```sh
composer install --no-plugins --no-scripts
composer test -- --fail-on-risky --fail-on-warning
composer validate --strict
composer audit
php benchmarks/router.php
```

See [UPGRADE.md](UPGRADE.md) for behavior changes and `../docs/guide/index.html` in the five-repository workspace for the maintained engineering guide. Normal HTTP operation requires no PSR-7 bridge; install `symfony/psr-http-message-bridge` and `nyholm/psr7` only if using optional PSR request/response conversion.

A fresh application per concurrent request is required. Sequential request fixes do not certify custom singletons, third-party guards or services for concurrent workers.
