# 🧠 Abrbit Laravel Scout Elasticsearch Driver

**A developer-friendly Laravel Scout driver for Elasticsearch — built for distributed, scalable, and clean search experiences.**  
Crafted with ❤️ by the [Abrbit](https://abrbit.com) team.

---

## 🚀 Features

✅ Plug-and-play with Laravel Scout  
✅ Uses official Elasticsearch REST API  
✅ Auto-maps documents & IDs for distributed clusters  
✅ Supports multi-field search (e.g., `title` + `description`)  
✅ Fully configurable via `config/services.php`  
✅ Optional `_bulk` indexing, concurrent fallback, timeouts and retries  
✅ Failed indexing throws, so queued Scout jobs are retried  
✅ Developer-friendly syntax — clean and minimal

---

## 📦 Installation

```bash
composer require abrbit/laravel-scout-elasticsearch
```

Then register your search service endpoint and credentials in `.env`:

```bash
SEARCH_URL=https://search.services.abrbit.com
SEARCH_TOKEN=your-api-token
```

---

## ⚙️ Configuration

In your `config/scout.php`, set the driver to `abrbit`:

```php
'driver' => 'abrbit',
```

And in `config/services.php`, add:

```php
'search' => [
    'url' => env('SEARCH_URL', 'https://example.com'),
    'token' => env('SEARCH_TOKEN'),

    // Everything below is optional; the values shown are the defaults.
    'timeout' => env('SEARCH_TIMEOUT', 10),                  // seconds per request
    'connect_timeout' => env('SEARCH_CONNECT_TIMEOUT', 3),   // seconds to establish the connection
    'retry_times' => env('SEARCH_RETRY_TIMES', 3),           // total attempts per request
    'retry_sleep_ms' => env('SEARCH_RETRY_SLEEP_MS', 200),   // backoff: 200ms, 400ms, 800ms, ...

    'bulk' => env('SEARCH_BULK', false),                     // use the _bulk endpoint (see below)
    'bulk_size' => env('SEARCH_BULK_SIZE', 500),             // operations per bulk request (Abrbit max: 1000)
    'bulk_max_bytes' => 5 * 1024 * 1024,                     // bytes per bulk request (Abrbit max: 5 MiB)
    'bulk_path' => 'indexes/{index}/_bulk',                  // bulk endpoint, relative to url
    'concurrency' => env('SEARCH_CONCURRENCY', 10),          // parallel requests when bulk is off
    'explicit_ids' => env('SEARCH_EXPLICIT_IDS', false),     // bulk off: PUT documents/{id} instead of POST documents

    'throw_on_index_errors' => true,   // update/delete/flush/createIndex/deleteIndex failures throw
    'throw_on_search_errors' => true,  // search/paginate/getSource/searchSource failures throw
],
```

| Key | Default | Description |
| --- | --- | --- |
| `timeout` | `10` | Request timeout in seconds. |
| `connect_timeout` | `3` | Connection timeout in seconds. |
| `retry_times` | `3` | Total attempts per request. Retries happen **only** on connection errors / timeouts, HTTP 429 and HTTP 5xx; other 4xx responses fail immediately. Exception: when neither `bulk` nor `explicit_ids` is enabled, `POST indexes/{index}/documents` is treated conservatively and retried only when the connection failed before the request was sent (DNS failure, connection refused, connect timeout), never on 429 / 5xx / read timeouts. |
| `retry_sleep_ms` | `200` | Base delay between attempts, doubled after each attempt. |
| `bulk` | `false` | Send writes through the Elasticsearch `_bulk` API (NDJSON). |
| `bulk_size` | `500` | Maximum operations per bulk request. The Abrbit service accepts at most 1000. |
| `bulk_max_bytes` | `5242880` (5 MiB) | Maximum body size per bulk request; requests are split before this is reached. A single document larger than this fails on its own. The Abrbit service accepts at most 5 MiB. |
| `bulk_path` | `indexes/{index}/_bulk` | Bulk endpoint; `{index}` is replaced by `searchableAs()`. |
| `concurrency` | `10` | Parallel requests (`Http::pool`) when `bulk` is off. |
| `explicit_ids` | `false` | When `bulk` is off, write documents with `PUT indexes/{index}/documents/{scoutKey}` instead of `POST indexes/{index}/documents`. |
| `throw_on_index_errors` | `true` | Throw `AbrbitIndexingException` on failed writes. When `false`, failures are logged as warnings (index + failed ids, never the token or document payloads). |
| `throw_on_search_errors` | `true` | Throw `AbrbitSearchException` on failed searches. When `false`, the 1.x behaviour is kept (the error body is returned as the result). |

All requests share one HTTP connection handler, so connections (and TLS sessions) are reused between requests, for example inside a long-running queue worker.

### Bulk indexing

With `bulk` enabled:

- `update()` sends `index` actions with `_id` set to the model's `getScoutKey()`, split by `bulk_size` operations and `bulk_max_bytes` bytes. Models whose `toSearchableArray()` is empty are skipped. Re-indexing a model overwrites its document, so it never creates a duplicate.
- `delete()` sends `delete` actions. Documents that are already gone (`404` / `not_found`) count as deleted.
- The bulk response is checked item by item. Items that failed with 429 / 5xx are re-sent on their own (up to `retry_times` attempts); items that succeeded are never repeated. If any item still failed, an `AbrbitIndexingException` lists the failed ids and reasons.
- A model with an empty scout key is reported as failed without blocking the rest of the request (the service rejects a whole request with an empty `_id`).
- The index must already exist: the Abrbit service does not create an index from a bulk request and answers `404 index not found`. A first `POST indexes/{index}/documents` creates it.

With `bulk` disabled (the default), one request is sent per document, `concurrency` at a time, and every response is checked the same way. Without `explicit_ids`, a document whose `POST` got a 429 / 5xx is reported as failed rather than re-sent (conservative retry rule); the queued job's retry re-indexes it.

> **Enable `bulk` on the Abrbit search service.** `POST indexes/{index}/_bulk` is supported (Elasticsearch NDJSON: `index`, `create`, `update`, `delete`). The service has **no** single-document delete route (`DELETE indexes/{index}/documents/{id}`), so `delete()` only works with `bulk` enabled; without it, every delete is reported as `HTTP 404 (endpoint not available)`.
>
> Existing indexes do **not** need a flush or re-import when you enable `bulk` or `explicit_ids`. The Abrbit service already stores `POST indexes/{index}/documents` payloads with `_id` = the payload's `id`, so bulk / `PUT` writes (`_id` = `getScoutKey()`) overwrite the same documents. This holds as long as `toSearchableArray()` puts the scout key in `id`, which is the default.

### Partial updates

To change a few fields without rebuilding the whole document (requires `bulk`):

```php
$products = Product::whereIn('id', $ids)->get();

$products->first()->searchableUsing()->partialUpdate($products, [
    'image_status',                               // taken from the model attribute
    'price' => fn ($product) => $product->final_price, // computed per model
]);
```

### Error handling

| Exception | Thrown by | Useful methods |
| --- | --- | --- |
| `Abrbit\Scout\Engines\Exceptions\AbrbitIndexingException` | `update`, `delete`, `partialUpdate`, `flush`, `createIndex`, `deleteIndex` | `failures()` (id => reason), `failedIds()`, `index()`, `getPrevious()` |
| `Abrbit\Scout\Engines\Exceptions\AbrbitSearchException` | `search` / `get()`, `paginate`, `raw()`, `getSource()`, `searchSource()` | `getPrevious()` (the underlying HTTP / connection exception) |

Both extend `AbrbitException` (a `RuntimeException`). When Scout runs with `'queue' => true`, a failed write fails the `MakeSearchable` / `RemoveFromSearch` job, so the queue worker retries it. Exception messages contain document ids and Elasticsearch error reasons, never the token or document payloads.

A 404 counts as "not found" (and is ignored by `delete()` / `flush()`) only when the body is JSON from the index, such as `"result":"not_found"` or `"index not found"`. A plain-text 404 means the service does not provide that route and is reported as `HTTP 404 (endpoint not available)`. The Abrbit service currently has no route for `flush()`, `createIndex()` or `deleteIndex()`.

---

## 🧩 Usage

You can use Laravel Scout’s native methods directly:

```php
use App\Models\Song;

// Paginate results
$songs = Song::search('عشق')->paginate(20);

// Get paginated raw source data
$songs = Song::search('عشق')->searchSource();
```

## 1. Get Eloquent Models (get())
   This is the standard Scout method. It returns an Eloquent Collection of your models, hydrated from the database based on the IDs returned from the search.

```php
use App\Models\Song;

// Returns a Collection of Song models
$songs = Song::search('عشق')->get();

// Paginate results
$songs = Song::search('عشق')->paginate(20);
```

## 2. Get Raw Source Data (getSource())
   This is the recommended method for APIs or when you don't need full Eloquent models. It returns a clean array containing only the _source data directly from Elasticsearch, avoiding any database queries and providing excellent performance.

```php
// Returns a clean array of the data stored in Elasticsearch
$songs = Song::search('شام')->getSource();

/*
Example Output:
[
    [
        "id" => 1,
        "title" => "شام غریبان",
        "description" => "..."
    ],
    [
        "id" => 2,
        "title" => "شام مهتاب",
        "description" => "..."
    ]
]
*/
```

## 3. Get Paginated Raw Source Data (searchSource())
   This method is similar to `getSource()`, but it supports pagination and is optimized for partial and short-term searches (e.g., 2 characters). It returns a paginated array of source data.

```php
// Returns a paginated array of the data stored in Elasticsearch
$songs = Song::search('شام')->searchSource();

// You can also specify the page and number of items per page
$songs = Song::search('شام')->searchSource($perPage = 15, $page = 2);

/*
Example Output:
[
    'data' => [
        [
            "id" => 1,
            "title" => "شام غریبان",
            "description" => "..."
        ],
        [
            "id" => 2,
            "title" => "شام مهتاب",
            "description" => "..."
        ]
    ],
    'total' => 100,
    'per_page' => 15,
    'current_page' => 2,
]
*/
```

## 4. Get the Raw Elasticsearch Response (raw())
   This method returns the entire, unprocessed JSON response from Elasticsearch. It is useful for debugging or when you need access to metadata like took, _shards, or max_score.

```php
// Returns the complete, raw response from the search engine
$rawResponse = Song::search('شام')->raw();
```


Your Eloquent model only needs to implement the `Searchable` trait:

```php
use Laravel\Scout\Searchable;
use Illuminate\Database\Eloquent\Model;

class Song extends Model
{
    use Searchable;

    protected $fillable = ['title', 'description'];

    public function toSearchableArray()
    {
        return [
            'id' => $this->getKey(),
            'title' => $this->title,
            'description' => $this->description,
        ];
    }
}
```

---

## 🔍 Example Query

Here’s what an example query looks like under the hood:

```json
{
  "from": 0,
  "size": 20,
  "query": {
    "multi_match": {
      "fields": ["title", "description"],
      "query": "شام"
    }
  }
}
```

---

## 🧠 How It Works

`AbrbitSearchEngine` is a custom Laravel Scout engine that:

- Sends search requests to your Elasticsearch instance.
- Writes documents with `_id` = the model's scout key when `bulk` or `explicit_ids` is enabled; otherwise the service uses the payload's `id` field as `_id`.
- Maps hits back to models through `_source.{scoutKeyName}`, falling back to `_id`, and keeps the hit order.
- Supports distributed, multi-tenant indexes like `tenant_songs` or `user_posts`.
- Maps `_source` data back into Eloquent models seamlessly.

Example search response:

```json
{
  "id": 443,
  "title": "شام غریبان",
  "description": "نوحه زیبای حاج محمود کریمی",
  "score": 6.6
}
```

---

## 🧰 Developer Notes

- Designed for **SaaS and multi-tenant** applications.  
- Compatible with any REST-compatible Elasticsearch cluster (v8+ recommended).  
- Works perfectly with Laravel Scout’s indexing pipeline.

---

## ⬆️ Upgrading to 3.0 (from 1.x or 2.0.0)

3.0 is a major release because failures are no longer silent. 2.0.0 is the 1.0.6 code plus `where()` filter support in queries, so these notes apply when upgrading from either 1.x or 2.0.0:

1. **Failed writes now throw** `AbrbitIndexingException`. Queued indexing jobs are retried. Synchronous saves (`scout.queue = false`) surface the error to the caller, so set `'throw_on_index_errors' => false` to keep 1.x behaviour (errors are logged instead).
2. **Failed searches now throw** `AbrbitSearchException` instead of returning the error body (which looked like "no results"). Set `'throw_on_search_errors' => false` to restore 1.x behaviour.
3. **Timeouts and retries** are on by default (10s / 3s connect / 3 attempts). Before, requests had Laravel's 30s timeout and no retries.
4. **PHP 8.2+** is required (was 8.1).
5. `map()` now returns a re-indexed collection (keys `0..n`), applies Scout `query()` callbacks, and attaches `_score` / `_id` / … as scout metadata. `keys()` and `lazyMap()` resolve ids the same way as `map()`.
6. Bulk indexing, `explicit_ids` and `partialUpdate()` are **opt-in**. With the defaults, documents are written with the same endpoint and body as in 1.x.

---

## 🧪 Testing

```bash
composer install
composer test
```

The suite uses Orchestra Testbench with `Http::fake()` and an in-memory SQLite database (requires `pdo_sqlite`).

---

## 🧑‍💻 Contributing

We welcome contributions!  
Feel free to open issues or submit pull requests.

---

## ⚖️ License

Released under the [MIT License](LICENSE).

---

## 🌐 About Abrbit

Abrbit provides modern cloud infrastructure and SaaS services —  
from DNS & mail hosting to AI, storage, and classroom platforms.

> 💡 Visit us at [abrbit.com](https://abrbit.com)