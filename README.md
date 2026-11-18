# GeoLookup

Laravel backend infrastructure for local administrative location lookup. Package name: `moztopia/geolookup`.

## Current implementation

The foundation registers a service provider, merges host-overridable configuration, and supports configuration publication. Search, coordinate lookup, HTTP endpoints, data installation and SDK generation are not implemented in 0.1.0.

Requires PHP 8.4.27+ within the 8.4 line, or 8.5.12+ within the 8.5 line. Tested: Laravel 12.73.0/Testbench 10 on PHP 8.4.27 and 8.5.12; Laravel 13.43.0/Testbench 11 on PHP 8.4.27. A real Laravel 13.43.0 Composer path installation verifies package discovery, configuration publication and configuration caching. PHP source is licensed under Apache-2.0; see `LICENSE` and `NOTICE`. Data licenses are independent.

## Local consumer installation

Until package publication, add a Composer path repository pointing to this checkout in your Laravel application's `composer.json`:

```json
{"repositories": [{"type": "path", "url": "/path/to/moztopia-geolookup"}]}
```

Then run:

```bash
composer require moztopia/geolookup:dev-develop
php artisan vendor:publish --tag=geolookup-config
```

Laravel discovers `Moztopia\GeoLookup\GeoLookupServiceProvider` automatically. The package does not download data or ask questions during Composer installation. Configure the data path and future opt-in HTTP interface in `config/geolookup.php`. The consuming application owns middleware, authentication, CORS and UI. No database migrations, queue workers or scheduler are required by the foundation.

The intended data store is a dedicated local SQLite file, separate from the application's transactional database. Country acquisition and import are follow-on work; discovering the provider never creates a file or opens a connection.

## Development

```bash
composer install
composer validate --strict
composer test
composer lint
```

Execute `php tests/Consumer/verify.php /path/to/consumer` to verify discovery in an actual consuming application after Composer installation; see `tests/Consumer/README.md`. Keep disposable consumers outside `tests/` to avoid recursive test discovery.

Dependencies and `composer.lock` remain local and untracked. The canonical source version is `VERSION`; Composer consumers resolve Git versions rather than an embedded Composer version. No CI or package publication is configured.

## Contributing and security

Use focused issue branches and pull requests to `develop`. Include consumer-contract tests and update usage documentation. Keep credentials and acquired raw data out of source control. Discuss behavior and code respectfully; maintainers may remove abusive contributions. For sensitive findings, use GitHub private vulnerability reporting when available; do not disclose exploit details in public issues. No separate security contact is currently configured.

Dataset coverage and licenses are tracked in [the GeoLookup Project](https://github.com/orgs/moztopia/projects/58). Missing village or boundary data must remain explicit.
