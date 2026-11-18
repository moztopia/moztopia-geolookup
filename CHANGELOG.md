# Changelog

## 0.9.0 — Leif

- Prepare the first owner-requested `main` release with the persistent release name Leif.
- Align the source, OpenAPI and SDK preset versions at 0.9.0; lookup behavior remains compatible with 0.3.0.
- Promote the existing Laravel backend, API-root discovery and wiki documentation. National data packs, the country installer and the Artisan SDK wrapper remain follow-on work.

## 0.3.0

- Serve OpenAPI YAML directly from the configured API root, including its trailing-slash form.
- Preserve the explicit `openapi.yaml` path, opt-in routing and host middleware.

## 0.2.0

- Add the shared local PHP lookup service and optional versioned JSON HTTP endpoints.
- Add read-only SQLite/FTS5 queries and explicit dataset provisioning with UUID identities.
- Support contextual names, aliases, postal exact/prefix ranking and variable-depth hierarchies.
- Resolve coordinates through Polygon/MultiPolygon containment, including holes, antimeridian rings and ambiguous branches.
- Bundle OpenAPI 3.0.4, response-contract tests, consumer HTTP smoke checks and three client-generation presets.
- Keep HTTP disabled by default; existing provider/configuration behavior remains available without installing data or SQLite support.

## 0.1.0

- Installable Laravel package foundation with provider discovery and publishable configuration.
- Isolated Pest/Testbench integration tests. No datasets or HTTP implementation in this version.
