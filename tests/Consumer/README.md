# Consumer installation smoke test

Execute `php tests/Consumer/verify.php /path/to/consumer` after installing the package through a Composer path repository in an actual Laravel application. This script executes checks; it is not reference-only code. It boots the consumer's console kernel and verifies discovery plus host-relative configuration without manually registering the provider.

The normal Pest suite does not create applications or perform network requests. Use the ignored `build/consumer/` folder for the disposable application, outside the test-discovery tree.
