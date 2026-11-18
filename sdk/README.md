# Client generation presets

The bundled OpenAPI contract targets Python, Dart Dio and TypeScript Axios. Presets supply package names, source version and owner metadata. Generated clients call the consuming Laravel application; they do not contain the lookup engine or national datasets.

With OpenAPI Generator 7.27.0, execute:

```bash
openapi-generator-cli validate -i openapi.yaml
openapi-generator-cli generate -i openapi.yaml -g python -c sdk/presets/python.yaml -o build/sdk/python
openapi-generator-cli generate -i openapi.yaml -g dart-dio -c sdk/presets/dart-dio.yaml -o build/sdk/dart-dio
openapi-generator-cli generate -i openapi.yaml -g typescript-axios -c sdk/presets/typescript-axios.yaml -o build/sdk/typescript-axios
```

These are commands to execute, not source scripts. The generator needs its documented Java runtime or isolated official CLI container; it is not a PHP runtime dependency. Keep generated dependencies and build output out of package source. Run language-specific dependency installation and compilation after generation. Dart Dio's built_value models require `dart run build_runner build`. TypeScript enables ES6 output to work with current compilers instead of the deprecated ES5 default.

Set each generated client's base URL to your Laravel application's configured route prefix. Host authentication credentials/interceptors are application choices; no API key is invented by the package. Match the SDK version to the contract served by that installation. When distributing generated clients, preserve the package's `LICENSE` and exact `NOTICE` as well as generator and third-party notices; generating code is not publication or dataset redistribution clearance.

For 0.2.0, generation was validated with OpenAPI Generator 7.27.0. Python 3.13/Pydantic 2 and Dart 3.14/built_value models parsed actual PHP search/reverse responses; TypeScript 6 on Node 24 compiled CommonJS and ES module output. Dart analysis reported four generator-produced unused import/field warnings and no errors. Generated SDKs are not published artifacts of this repository.

The proposed Artisan SDK-generation wrapper is tracked in [issue #14](https://github.com/moztopia/moztopia-geolookup/issues/14). It needs runtime discovery, explicit output handling, deterministic versioning and tests.
