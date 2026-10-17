# Changelog

All notable changes to `abrbit/laravel-scout-elasticsearch` are documented here.
This project follows [Semantic Versioning](https://semver.org/).

## [3.0.0] - 2026-10-17

Major release: failures that were silently ignored now throw by default, and PHP 8.2 is the new minimum.
See "Upgrading to 3.0" in the README.

### Added
- Optional Elasticsearch `_bulk` indexing (`services.search.bulk`, default `false`): NDJSON `index` / `delete`
  actions with `_id` = `getScoutKey()`, chunked by `bulk_size` (default 500), path configurable via `bulk_path`.
- Per-item bulk response checking; failed ids and reasons are reported through `AbrbitIndexingException`.
- Concurrent fallback when bulk is disabled: requests are sent with `Http::pool`, `concurrency` (default 10) at a time,
  and every response is checked.
- `explicit_ids` option to write single documents with `PUT indexes/{index}/documents/{id}`.
- `partialUpdate($models, $fields)` (bulk `update` with `doc`, requires `bulk`).
- One shared, configured HTTP client for every request: `timeout` (10s), `connect_timeout` (3s),
  `retry_times` (3) with exponential `retry_sleep_ms` (200ms). Retries only on connection errors, 429 and 5xx.
  `POST indexes/{index}/documents` (bulk and `explicit_ids` both off) is treated conservatively and retried only when
  the connection failed before the request was sent, never on 429 / 5xx / read timeouts.
  Connections are reused between requests.
- `AbrbitIndexingException`, `AbrbitSearchException` (both extend `AbrbitException`).
- `throw_on_index_errors` / `throw_on_search_errors` options (default `true`); set to `false` for 1.x behaviour.
- PHPUnit / Orchestra Testbench test suite.

### Changed
- **Breaking:** write operations (`update`, `delete`, `flush`, `createIndex`, `deleteIndex`) throw on failure.
- **Breaking:** search operations throw `AbrbitSearchException` on HTTP / connection failure.
- **Breaking:** requires PHP 8.2+.
- Uses `getScoutKey()` / `getScoutKeyName()` everywhere instead of `getKey()`.
- `mapIds`, `keys`, `map` and `lazyMap` resolve ids the same way (`_source.{scoutKeyName}`, falling back to `_id`)
  and preserve hit order. `map()` returns a re-indexed collection and honours Scout `query()` callbacks.
- `getTotalCount()` also accepts integer `hits.total`.
- Declares `guzzlehttp/guzzle` and `illuminate/http` as dependencies (already present in Laravel apps).

### Upgrade notes
- Enabling `bulk` or `explicit_ids` does not require flushing or re-importing existing indexes: the Abrbit service
  already uses the payload's `id` as `_id` for `POST indexes/{index}/documents`, so documents keep the same `_id`
  (verified against a production index of 19,019 documents with no duplicates).

### Fixed
- `mapIds()` read `hits.*.id`, which does not exist in Elasticsearch responses, so `keys()` always returned an empty collection.
- `delete()` of an already-removed document no longer counts as an error.

## [2.0.0] - 2025-12-18

- Tag for commit `52b960f` ("Improve query"), on top of 1.0.6: `search()`, `paginate()` and `searchSource()`
  wrap the main query in a `bool` query and apply Scout `where()` constraints as `term` filters.
  Committed 2025-12-18, tagged 2026-10-17.
