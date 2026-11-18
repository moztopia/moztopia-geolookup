# Consumer installation smoke test

Execute `php tests/Consumer/verify.php /path/to/consumer` after installing the package through a Composer path repository in an actual Laravel application. This script executes checks; it is not reference-only code. It boots the consumer's console kernel and verifies discovery plus host-relative configuration without manually registering the provider.

The normal Pest suite does not create applications or perform network requests. Use the ignored `build/consumer/` folder for the disposable application, outside the test-discovery tree.

Execute `php tests/Consumer/verify-api.php /path/to/disposable-consumer` for the HTTP smoke check. This second script requires the opt-in routes enabled and the explicit `tests/Fixtures/Locations` dataset provisioned in that disposable application; its coordinates are synthetic. It dispatches requests through Laravel's HTTP kernel without starting a server. Repeat after `php artisan config:cache` and `php artisan route:cache` to verify cached consumption. Never install these synthetic geometries into a production dataset.
