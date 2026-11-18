# GeoLookup

Laravel backend infrastructure for local administrative location lookup. Package: `moztopia/geolookup`. Source license: Apache-2.0; preserve `LICENSE` and the exact Moztopia `NOTICE`. Dataset licenses are independent.

## Implemented in 0.2.0

- Shared PHP service and opt-in JSON endpoints for search, place detail, children, ancestors and reverse lookup.
- SQLite/FTS5 indexed name, alias and ancestor-context search. Exact postal matches precede postal prefixes; codes remain strings with leading zeros.
- Country-specific hierarchy depths, any-node selection, and additional geographic relationships independent of the display parent.
- Polygon/MultiPolygon containment with holes, exterior edges, antimeridian rings and ambiguous branches. No nearest-centre fallback.
- Bundled `openapi.yaml`, Python/Dart Dio/TypeScript Axios generation presets, contract-response tests and real Laravel consumer smoke scripts.

**No national data packs are installed or bundled.** The name/postal test facts are source-backed; all test geometries and synthetic nodes are explicitly fixtures. Dataset acquisition, the [country installer](https://github.com/moztopia/moztopia-geolookup/issues/15), and the [Artisan SDK-generation wrapper](https://github.com/moztopia/moztopia-geolookup/issues/14) remain follow-on work. This version provides an executable backend with explicit dataset provisioning; it does not promise country completeness or official village boundaries.

## Requirements and installation

Laravel 12 or 13, PHP 8.4.27+ within 8.4 or 8.5.12+ within 8.5. Querying/provisioning datasets also needs PDO SQLite with FTS5; provider discovery and the default disabled HTTP interface do not require installed data. Packagist publication and deployment are not configured.

Until package publication, add a path repository in a consuming Laravel application's `composer.json`:

```json
{"repositories": [{"type": "path", "url": "/path/to/moztopia-geolookup"}]}
```

Then execute:

```bash
composer require moztopia/geolookup:dev-develop
php artisan vendor:publish --tag=geolookup-config
```

Laravel discovers the provider automatically. The package never downloads data or prompts during Composer installation. No application database migrations, queue workers, scheduler or UI are required.

## Provision a local dataset

Use a new file in an existing directory. Existing files are never overwritten. Assign stable UUIDv4/v7 identities once and retain them across updates; external identifiers go in `source`, not `id`.

```php
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Moztopia\GeoLookup\Data\SqliteDataset;

$directory = storage_path('app/geolookup');
File::ensureDirectoryExists($directory);

SqliteDataset::create($directory.'/locations.sqlite', [
    [
        'id' => (string) Str::uuid(),
        'name' => 'Thailand',
        'type' => 'country',
        'country_code' => 'TH',
        'aliases' => ['TH'],
    ],
]);
```

That example creates only a selectable country, not a Thailand address dataset. Actual imports must provide the complete parent chain. Optional place fields: `parent_id`, `local_type`, `aliases`, `postal_codes`, `source` (provenance object), and `relationships` (list of `{type, place_id}`). The primary parent is the display/navigation chain; additional geographic relationships can represent overlaps without inventing a single county parent. They are metadata, not extra ancestors or search scopes.

An optional third argument supplies boundaries as a list of `['place_id' => $uuid, 'geometry' => $geoJsonGeometry]`. Coordinates are WGS84 longitude/latitude pairs. The producer must establish source rights, version, completeness and valid non-self-intersecting topology. The package checks structural validity, not the legal authority of a border. Containment uses planar GeoJSON segments with longitude unwrapping, not geodesic surveying. Exterior edges match; holes are excluded, including their edges. Point features cannot substitute for administrative polygons.

`database/schema.sql` documents storage schema 1. Use the provisioning API to build names, FTS context and readiness metadata together; applying that SQL alone does not build a ready dataset. Runtime connections are read-only. For updates, provision a new versioned file and switch the host configuration, then restart long-lived workers so they open the new file. Do not rewrite an active database in place.

## PHP API

```php
use Moztopia\GeoLookup\GeoLookup;

$geo = app(GeoLookup::class);
$geo->search('Bethel Acres, OK');
$geo->search('Thaiburi, Thailand', types: ['subdistrict']);
$geo->search('80160', country: 'TH', limit: 10, offset: 0);
$geo->search('Thai', within: $thailandId);
$geo->place($placeId);
$geo->children($placeId, limit: 10);
$geo->ancestors($placeId);
$geo->reverse(latitude: $latitude, longitude: $longitude, stopAt: 'country');
```

Every place can be a final selection, including countries and nodes with children. Ancestors run country-to-parent, excluding self; `hierarchy` includes self. Types are country-specific identifiers, not a global numeric depth. PHP methods return the same envelope as HTTP and throw `Exceptions\LookupException` for domain/validation failures.

Search folds case, separators and Latin accents while preserving native-script names; known transliterations/spellings should be included as aliases. All typed fragments must match indexed name/alias/ancestor context, or the compact query must match a directly associated postal prefix. Postal spaces/punctuation are ignored for matching. Ranking: exact postal, postal prefix, exact own name/alias, then other indexed name/context matches. Ties use name and UUID. No fuzzy typo-correction guarantee is made.

Search and children return `data` plus `meta.limit`, `offset`, `has_more`, and `next_offset`; limit is 1–100, a nonnegative integer offset (bounded by the server's integer range). Results are deterministic for a fixed immutable dataset. An empty result is distinct from a missing dataset or unknown place.

## Included HTTP interface

Set `http.enabled` to `true` in the published configuration. Configure `http.prefix` and `http.middleware` there; defaults are `api/geolookup/v1` and the host's `api` middleware group. The consuming application owns authentication, authorization, CORS and throttling. The package adds no frontend or alternate server.

```http
GET /api/geolookup/v1/search?q=Bethel%20Acres%2C%20OK
GET /api/geolookup/v1/search?q=80160&country=TH&types=subdistrict&limit=10
GET /api/geolookup/v1/places/{id}
GET /api/geolookup/v1/places/{id}/children
GET /api/geolookup/v1/places/{id}/ancestors
GET /api/geolookup/v1/reverse?latitude=8.66239&longitude=99.87876&stop_at=subdistrict
GET /api/geolookup/v1/openapi.yaml
```

The coordinate example needs genuine installed polygons to produce an actual administrative result. Reverse responses contain matching `place`/`resolution` branches, report ambiguity, and deduplicate a common requested parent. `complete=true` means resolution at the requested type (or deepest installed boundary when no type is requested), **not complete geographic coverage**. An unavailable stop type returns the deepest installed match with `complete=false`. No matched boundary yields an empty list; it does not establish that the coordinate has no real administrative location.

Package-handled errors always use JSON `{error: {code, message, fields}}`, including without an Accept header: 422 invalid input, 404 unknown place, 503 unavailable/incompatible data. Host middleware errors remain governed by the application. API responses do not expose local dataset paths or SQL errors.

The served contract reflects the configured relative prefix and works without a dataset. You can also execute `php artisan vendor:publish --tag=geolookup-openapi` to copy the canonical file to `openapi/geolookup.yaml`. Static published copies keep the canonical default server URL; configure generated clients with the actual application URL/prefix. See [SDK presets](sdk/README.md).

## Development and validation

```bash
composer install
composer validate --strict
composer test
composer lint
```

Execute `php tests/Consumer/verify.php /path/to/consumer` to verify real Composer discovery; execute the fixture-only HTTP smoke check described in `tests/Consumer/README.md` to test the HTTP kernel, configuration caching and route caching. Disposable consumers belong in ignored `build/consumer/`, outside Pest's discovery tree. Generated clients and dependency lockfiles stay untracked.

The source version is `VERSION`, with matching immutable numeric Git tags. Composer consumers resolve Git versions rather than an embedded Composer version. CI, public package publication and deployment are not implied by local checks or tags.

Version 0.2.0 validation used Laravel 12.73.0/Testbench 10 and Laravel 13.43.0/Testbench 11 on PHP 8.4.27 and 8.5.12 (43 tests, 135 assertions per combination). A real Laravel 13 consumer passed the HTTP smoke check with configuration and routes cached. Run matrix checks sequentially or in isolated copies: configuration-publication tests write to Testbench's disposable application directory.

## Contributing and security

Use focused issue branches and pull requests to `develop`, include consumer-contract tests and keep usage docs current. Keep credentials and acquired raw data out of source. Discuss contributions respectfully; maintainers may remove abusive content. Use GitHub private vulnerability reporting when available for sensitive findings; no separate security contact is configured. Dataset coverage and licensing remain tracked in [GeoLookup](https://github.com/orgs/moztopia/projects/58).
