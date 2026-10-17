<?php

namespace Abrbit\Scout\Engines;

use Abrbit\Scout\Engines\Exceptions\AbrbitIndexingException;
use Abrbit\Scout\Engines\Exceptions\AbrbitSearchException;
use Closure;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use JsonException;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use LogicException;
use Throwable;

class AbrbitSearchEngine extends Engine
{
  /**
   * Default values for the `services.search.*` configuration keys.
   */
  protected const DEFAULTS = [
    'timeout' => 10,
    'connect_timeout' => 3,
    'retry_times' => 3,
    'retry_sleep_ms' => 200,
    'bulk' => false,
    'bulk_size' => 500,
    'bulk_max_bytes' => 5 * 1024 * 1024,
    'bulk_path' => 'indexes/{index}/_bulk',
    'concurrency' => 10,
    'explicit_ids' => false,
    'throw_on_index_errors' => true,
    'throw_on_search_errors' => true,
  ];

  protected const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

  /**
   * Guzzle handler shared by every request this engine sends, so that
   * TCP / TLS connections are kept alive and reused between requests.
   *
   * @var callable|null
   */
  protected $handler;

  protected function indexName($model)
  {
    return $model->searchableAs();
  }

  /**
   * Update / index the given models in the search engine.
   */
  public function update($models)
  {
    $this->groupByIndex($models)->each(function ($models, $index) {
      $documents = [];
      $failures = [];

      foreach ($models as $model) {
        $payload = $model->toSearchableArray();

        if (empty($payload)) {
          continue;
        }

        $documents[(string) $model->getScoutKey()] = $payload;
      }

      if (empty($documents)) {
        return;
      }

      if ($this->usesBulk()) {
        $operations = [];
        foreach ($documents as $id => $payload) {
          $operations[] = ['index', $id, $payload];
        }

        $failures = $this->sendBulk($index, $operations);
      } else {
        $explicitIds = (bool) $this->option('explicit_ids');

        // The service takes `_id` from payload.id, but a POST is still retried conservatively
        // (only when it never reached the server); see failedBeforeSending().
        $failures = $this->sendConcurrently($documents, function (PendingRequest $request, $payload, $id) use ($index, $explicitIds) {
          return $explicitIds
            ? $request->put($this->documentsPath($index, $id), $payload)
            : $request->post($this->documentsPath($index), $payload);
        }, idempotent: $explicitIds);
      }

      $this->reportIndexingFailures('indexing', $index, $failures);
    });
  }

  /**
   * Update only the given fields of already indexed documents (bulk `update` with `doc`).
   *
   * `$fields` is a list of model attribute names, and / or `field => value` pairs where
   * the value may be a Closure receiving the model, e.g.
   * `['image_status', 'price' => fn ($product) => $product->final_price]`.
   *
   * Requires `services.search.bulk` to be enabled.
   */
  public function partialUpdate($models, array $fields)
  {
    if (! $this->usesBulk()) {
      throw new LogicException('partialUpdate() requires the bulk API; enable services.search.bulk first.');
    }

    $this->groupByIndex($models)->each(function ($models, $index) use ($fields) {
      $operations = [];

      foreach ($models as $model) {
        $doc = [];
        foreach ($fields as $key => $value) {
          if (is_int($key)) {
            $doc[$value] = $model->getAttribute($value);
          } else {
            $doc[$key] = $value instanceof Closure ? $value($model) : $value;
          }
        }

        if (! empty($doc)) {
          $operations[] = ['update', (string) $model->getScoutKey(), ['doc' => $doc]];
        }
      }

      $this->reportIndexingFailures('partial update', $index, $this->sendBulk($index, $operations));
    });
  }

  /**
   * Remove the given models from the search index.
   *
   * Documents that are already missing from the index (404 / not_found) are not an error.
   */
  public function delete($models)
  {
    $this->groupByIndex($models)->each(function ($models, $index) {
      $ids = [];
      foreach ($models as $model) {
        $ids[(string) $model->getScoutKey()] = true;
      }

      if (empty($ids)) {
        return;
      }

      if ($this->usesBulk()) {
        $operations = [];
        foreach (array_keys($ids) as $id) {
          $operations[] = ['delete', (string) $id, null];
        }

        $failures = $this->sendBulk($index, $operations);
      } else {
        $failures = $this->sendConcurrently($ids, function (PendingRequest $request, $_, $id) use ($index) {
          return $request->delete($this->documentsPath($index, $id));
        }, ignoreNotFound: true);
      }

      $this->reportIndexingFailures('delete', $index, $failures);
    });
  }

  /**
   * Perform the given search.
   */
  public function search(Builder $builder)
  {
    $fields = method_exists($builder->model, 'getSearchableFields')
      ? $builder->model->getSearchableFields()
      : ($builder->model->searchableFields ?? ['id']); // fallback for older models

    $query = $this->buildQuery($builder, [
      'multi_match' => [
        'query' => $builder->query,
        'fields' => $fields,
      ],
    ]);

    $extra = $builder->options['custom'] ?? [];

    return $this->performSearch($builder, [
      'query' => $query,
      ...$extra,
    ]);
  }

  /**
   * Paginate the given search.
   */
  public function paginate(Builder $builder, $perPage, $page)
  {
    $fields = method_exists($builder->model, 'getSearchableFields')
      ? $builder->model->getSearchableFields()
      : ['id'];

    $query = $this->buildQuery($builder, [
      'multi_match' => [
        'query' => $builder->query,
        'fields' => $fields,
      ],
    ]);

    return $this->performSearch($builder, [
      'from' => ($page - 1) * $perPage,
      'size' => $perPage,
      'query' => $query,
      ...($builder->options['custom'] ?? []),
    ]);
  }

  /**
   * Paginated search returning the `_source` of each hit (used by the `searchSource` macro).
   */
  public function searchSource(Builder $builder, $perPage, $page): array
  {
    $fields = method_exists($builder->model, 'getSearchableFields')
      ? $builder->model->getSearchableFields()
      : ['id'];

    $mainQuery = is_string($builder->query)
      ? [
        'query_string' => [
          'query' => '*' . $builder->query . '*',
          'fields' => $fields,
        ],
      ]
      : $builder->query;

    $response = $this->performSearch($builder, [
      'from' => ($page - 1) * $perPage,
      'size' => $perPage,
      'query' => $this->buildQuery($builder, $mainQuery),
      ...($builder->options['custom'] ?? []),
    ]);

    return [
      'data' => collect($response['hits']['hits'] ?? [])->pluck('_source')->all(),
      'total' => $this->getTotalCount($response),
      'per_page' => (int) $perPage,
      'current_page' => (int) $page,
    ];
  }

  /**
   * Get the keys of the models matching the search, resolved with the model's scout key name.
   */
  public function keys(Builder $builder)
  {
    return $this->resolveHitIds($this->search($builder), $this->sourceKeyName($builder->model));
  }

  /**
   * Map the given results to IDs.
   */
  public function mapIds($results)
  {
    return $this->resolveHitIds($results, 'id');
  }

  /**
   * Map the given results to models, in hit order.
   */
  public function map(Builder $builder, $results, $model)
  {
    [$ids, $hitsById] = $this->indexHits($results, $model);

    if (empty($ids)) {
      return $model->newCollection();
    }

    $models = $model->getScoutModelsByIds($builder, $ids)
      ->keyBy(fn ($m) => (string) $m->getScoutKey());

    $mapped = [];
    foreach ($hitsById as $id => $hit) {
      if (isset($models[$id])) {
        $mapped[] = $this->withHitMetadata($models[$id], $hit);
      }
    }

    return $model->newCollection($mapped);
  }

  /**
   * Lazy map for large result sets, in hit order.
   */
  public function lazyMap(Builder $builder, $results, $model)
  {
    [$ids, $hitsById] = $this->indexHits($results, $model);

    if (empty($ids)) {
      return LazyCollection::make([]);
    }

    $positions = array_flip(array_keys($hitsById));

    return $model->queryScoutModelsByIds($builder, $ids)
      ->cursor()
      ->filter(fn ($m) => isset($positions[(string) $m->getScoutKey()]))
      ->map(fn ($m) => $this->withHitMetadata($m, $hitsById[(string) $m->getScoutKey()]))
      ->sortBy(fn ($m) => $positions[(string) $m->getScoutKey()])
      ->values();
  }

  /**
   * Get the total count from a raw result.
   */
  public function getTotalCount($results)
  {
    $total = $results['hits']['total'] ?? 0;

    return (int) (is_array($total) ? ($total['value'] ?? 0) : $total);
  }

  /**
   * Flush all of the model's records from the index.
   */
  public function flush($model)
  {
    $index = $this->indexName($model);

    $this->sendAdminRequest('flush', $index, fn (PendingRequest $request) => $request->delete($this->documentsPath($index)), ignoreNotFound: true);
  }

  /**
   * Create a new index.
   */
  public function createIndex($name, array $options = [])
  {
    $this->sendAdminRequest('create index', $name, fn (PendingRequest $request) => $request->put('indexes/' . $name, $options));
  }

  /**
   * Delete an index.
   */
  public function deleteIndex($name)
  {
    $this->sendAdminRequest('delete index', $name, fn (PendingRequest $request) => $request->delete('indexes/' . $name));
  }

  /**
   * Map the raw results to a simple array of _source documents.
   */
  public function mapSource($results): array
  {
    if (! isset($results['hits']['hits']) || count($results['hits']['hits']) === 0) {
      return [];
    }

    return collect($results['hits']['hits'])->pluck('_source')->all();
  }

  /**
   * Build a request with the shared configuration: base URL, token, timeouts and retries.
   *
   * Retries happen only on connection errors / timeouts, HTTP 429 and HTTP 5xx.
   */
  protected function http(?PendingRequest $request = null, bool $throw = true, bool $retry = true): PendingRequest
  {
    $request = ($request ?? Http::withOptions([]))
      ->baseUrl($this->baseUrl())
      ->withToken((string) $this->option('token'))
      ->timeout((int) $this->option('timeout'))
      ->connectTimeout((int) $this->option('connect_timeout'))
      ->setHandler($this->handler ??= Utils::chooseHandler());

    if ($retry) {
      $request->retry(
        max(1, (int) $this->option('retry_times')),
        fn (int $attempt) => $this->retryDelay($attempt),
        fn (Throwable $e) => $this->isRetryable($e),
        $throw
      );
    }

    return $throw ? $request->throw() : $request;
  }

  /**
   * Exponential backoff in milliseconds: retry_sleep_ms, 2x, 4x, ...
   */
  protected function retryDelay(int $attempt): int
  {
    return (int) $this->option('retry_sleep_ms') * (2 ** ($attempt - 1));
  }

  protected function isRetryable(Throwable $e): bool
  {
    if ($e instanceof ConnectionException) {
      return true;
    }

    if ($e instanceof RequestException) {
      $status = $e->response->status();

      return $status === 429 || $status >= 500;
    }

    return false;
  }

  /**
   * Whether the request certainly never reached the server, so even a non-idempotent request
   * can be retried: DNS / connect failures, or cURL never getting to the transfer phase.
   */
  protected function failedBeforeSending(Throwable $e): bool
  {
    if (! $e instanceof ConnectionException || ! $e->getPrevious() instanceof GuzzleConnectException) {
      return false;
    }

    $context = $e->getPrevious()->getHandlerContext();

    if (in_array($context['errno'] ?? null, [CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], true)) {
      return true;
    }

    return isset($context['pretransfer_time']) && (float) $context['pretransfer_time'] === 0.0;
  }

  protected function option(string $key)
  {
    return config('services.search.' . $key) ?? (self::DEFAULTS[$key] ?? null);
  }

  protected function baseUrl(): string
  {
    return rtrim((string) $this->option('url'), '/');
  }

  protected function usesBulk(): bool
  {
    return (bool) $this->option('bulk');
  }

  protected function documentsPath(string $index, $id = null): string
  {
    $path = 'indexes/' . $index . '/documents';

    return $id === null ? $path : $path . '/' . rawurlencode((string) $id);
  }

  protected function groupByIndex($models)
  {
    return collect($models)->groupBy(fn ($model) => $this->indexName($model));
  }

  /**
   * Send bulk operations (`[action, id, body|null]`) in chunks of at most `bulk_size`
   * operations and `bulk_max_bytes` bytes.
   *
   * Items that fail with 429 / 5xx are re-sent on their own (up to `retry_times` attempts);
   * other failed items are reported as they are.
   *
   * @return array<string, string> Failed document id => reason.
   *
   * @throws AbrbitIndexingException when a whole bulk request fails.
   */
  protected function sendBulk(string $index, array $operations): array
  {
    $failures = [];
    $entries = [];
    $maxBytes = max(1, (int) $this->option('bulk_max_bytes'));

    foreach ($operations as [$action, $id, $body]) {
      $id = (string) $id;

      // One invalid line makes the service reject the whole request, so check ids up front.
      if ($id === '') {
        $failures['(empty)'] = 'empty scout key';
        continue;
      }

      try {
        $line = json_encode([$action => ['_id' => $id]], self::JSON_FLAGS) . "\n";
        if ($body !== null) {
          $line .= json_encode($body, self::JSON_FLAGS) . "\n";
        }
      } catch (JsonException $e) {
        $failures[$id] = 'json encoding failed: ' . $e->getMessage();
        continue;
      }

      if (strlen($line) > $maxBytes) {
        $failures[$id] = 'document exceeds bulk_max_bytes (' . strlen($line) . ' bytes)';
        continue;
      }

      $entries[] = [$id, $line];
    }

    $path = str_replace('{index}', $index, (string) $this->option('bulk_path'));
    $maxAttempts = max(1, (int) $this->option('retry_times'));

    foreach ($this->chunkBulkEntries($entries, $maxBytes) as $pending) {
      for ($attempt = 1; ! empty($pending); $attempt++) {
        $ids = array_column($pending, 0);

        try {
          $response = $this->http()
            ->withBody(implode('', array_column($pending, 1)), 'application/x-ndjson')
            ->post($path);
        } catch (RequestException|ConnectionException $e) {
          $reason = $e instanceof RequestException
            ? $this->describeResponse($e->response)
            : 'connection error';

          throw AbrbitIndexingException::forFailures('bulk request', $index, $failures + array_fill_keys($ids, $reason), $e);
        }

        $retry = [];
        foreach ($this->parseBulkResponse($response, $ids) as $position => [$id, $status, $reason]) {
          if ($attempt < $maxAttempts && ($status === 429 || $status >= 500) && isset($pending[$position])) {
            $retry[] = $pending[$position];
          } else {
            $failures[$id] = $reason;
          }
        }

        if (! empty($retry) && ($sleep = $this->retryDelay($attempt)) > 0) {
          usleep($sleep * 1000);
        }

        $pending = $retry;
      }
    }

    return $failures;
  }

  /**
   * Split encoded `[id, ndjson]` entries by operation count and body size.
   */
  protected function chunkBulkEntries(array $entries, int $maxBytes): array
  {
    $maxOperations = max(1, (int) $this->option('bulk_size'));
    $chunks = [];
    $chunk = [];
    $bytes = 0;

    foreach ($entries as $entry) {
      $length = strlen($entry[1]);

      if (! empty($chunk) && (count($chunk) >= $maxOperations || $bytes + $length > $maxBytes)) {
        $chunks[] = $chunk;
        $chunk = [];
        $bytes = 0;
      }

      $chunk[] = $entry;
      $bytes += $length;
    }

    if (! empty($chunk)) {
      $chunks[] = $chunk;
    }

    return $chunks;
  }

  /**
   * @return array<int, array{0: string, 1: int, 2: string}> Position => [id, status, reason] of failed items.
   */
  protected function parseBulkResponse(Response $response, array $ids): array
  {
    $items = $response->json('items');

    if (! is_array($items)) {
      return array_map(fn ($id) => [$id, 0, 'unexpected bulk response (no items)'], $ids);
    }

    $failures = [];
    foreach ($items as $position => $item) {
      $action = array_key_first((array) $item);
      $result = (array) ($item[$action] ?? []);
      $id = (string) ($result['_id'] ?? $ids[$position] ?? $position);
      $status = (int) ($result['status'] ?? 200);

      if ($action === 'delete' && ($status === 404 || ($result['result'] ?? null) === 'not_found')) {
        continue;
      }

      if (isset($result['error']) || $status >= 300) {
        $failures[$position] = [$id, $status, $this->describeError($result['error'] ?? null, "HTTP {$status}")];
      }
    }

    return $failures;
  }

  /**
   * Send one request per item concurrently (`concurrency` at a time) and check every response.
   *
   * Retryable failures (connection errors, 429, 5xx) are re-sent here rather than by the HTTP
   * client, because Laravel 10 does not apply `retry()` to pooled requests. Non-idempotent
   * requests are only re-sent when they failed before reaching the server.
   *
   * @param  array<string, mixed>  $items  Document id => payload.
   * @param  Closure(PendingRequest, mixed, string): mixed  $send
   * @return array<string, string> Failed document id => reason.
   */
  protected function sendConcurrently(array $items, Closure $send, bool $ignoreNotFound = false, bool $idempotent = true): array
  {
    $failures = [];
    $maxAttempts = max(1, (int) $this->option('retry_times'));

    foreach (array_chunk($items, max(1, (int) $this->option('concurrency')), true) as $batch) {
      for ($attempt = 1; ! empty($batch); $attempt++) {
        $ids = array_map('strval', array_keys($batch));

        $results = Http::pool(function (Pool $pool) use ($batch, $ids, $send) {
          foreach ($ids as $position => $id) {
            $send($this->http($pool->as((string) $position), throw: false, retry: false), $batch[$id], $id);
          }
        });

        $retry = [];
        foreach ($ids as $position => $id) {
          $result = $results[$position] ?? null;

          if ($result instanceof Response && ($result->successful() || ($ignoreNotFound && $this->isNotFound($result)))) {
            continue;
          }

          $exception = match (true) {
            $result instanceof Response => $result->toException(),
            // Laravel 10 hands back Guzzle's exception for failed pooled connections.
            $result instanceof GuzzleConnectException => new ConnectionException($result->getMessage(), 0, $result),
            default => $result,
          };

          $retryable = $exception instanceof Throwable
            && ($idempotent ? $this->isRetryable($exception) : $this->failedBeforeSending($exception));

          if ($attempt < $maxAttempts && $retryable) {
            $retry[$id] = $batch[$id];
          } elseif ($exception instanceof RequestException) {
            $failures[$id] = $this->describeResponse($exception->response);
          } else {
            $failures[$id] = $exception instanceof ConnectionException ? 'connection error' : 'request failed';
          }
        }

        if (! empty($retry) && ($sleep = $this->retryDelay($attempt)) > 0) {
          usleep($sleep * 1000);
        }

        $batch = $retry;
      }
    }

    return $failures;
  }

  /**
   * Send a single write request that is not tied to individual documents.
   */
  protected function sendAdminRequest(string $operation, string $index, Closure $send, bool $ignoreNotFound = false): void
  {
    try {
      $send($this->http());
    } catch (RequestException $e) {
      if ($ignoreNotFound && $this->isNotFound($e->response)) {
        return;
      }

      $this->reportIndexingFailures($operation, $index, ['*' => $this->describeResponse($e->response)], $e);
    } catch (ConnectionException $e) {
      $this->reportIndexingFailures($operation, $index, ['*' => 'connection error'], $e);
    }
  }

  /**
   * Throw (default) or log the collected failures, depending on `throw_on_index_errors`.
   */
  protected function reportIndexingFailures(string $operation, string $index, array $failures, ?Throwable $previous = null): void
  {
    if (empty($failures)) {
      return;
    }

    $exception = AbrbitIndexingException::forFailures($operation, $index, $failures, $previous);

    if ($this->option('throw_on_index_errors')) {
      throw $exception;
    }

    Log::warning($exception->getMessage(), [
      'index' => $index,
      'failed_ids' => array_slice($exception->failedIds(), 0, 100),
    ]);
  }

  /**
   * Send a search body to the index of the builder's model.
   */
  protected function performSearch(Builder $builder, array $body)
  {
    $index = $this->indexName($builder->model);

    try {
      return $this->http()->post('indexes/' . $index . '/_search', $body)->json();
    } catch (RequestException $e) {
      if (! $this->option('throw_on_search_errors')) {
        return $e->response->json();
      }

      throw AbrbitSearchException::fromThrowable($index, $e);
    } catch (ConnectionException $e) {
      if (! $this->option('throw_on_search_errors')) {
        throw $e;
      }

      throw AbrbitSearchException::fromThrowable($index, $e);
    }
  }

  /**
   * Wrap the main query in a bool query with the builder's `where` filters and apply the callback.
   */
  protected function buildQuery(Builder $builder, $mainQuery)
  {
    $filters = [];
    foreach ($builder->wheres as $field => $value) {
      $filters[] = ['term' => [$field => $value]];
    }

    $query = [
      'bool' => [
        'must' => $mainQuery,
      ],
    ];

    if (count($filters) > 0) {
      $query['bool']['filter'] = $filters;
    }

    if ($builder->callback) {
      $query = call_user_func($builder->callback, $query, $builder);
    }

    return $query;
  }

  /**
   * The key name inside `_source` that holds the model's scout key.
   */
  protected function sourceKeyName($model): string
  {
    return Str::afterLast($model->getScoutKeyName(), '.');
  }

  /**
   * Resolve a hit's model id: `_source.{key}` first, then `_id`.
   *
   * Both are normally equal (the service stores payload.id as `_id`); `_source` wins so that a
   * custom scout key stored under its own field still maps to the right model.
   */
  protected function hitId(array $hit, string $keyName): ?string
  {
    $id = $hit['_source'][$keyName] ?? $hit['_id'] ?? null;

    return $id === null || is_array($id) ? null : (string) $id;
  }

  protected function resolveHitIds($results, string $keyName)
  {
    return collect($results['hits']['hits'] ?? [])
      ->map(fn ($hit) => $this->hitId((array) $hit, $keyName))
      ->filter(fn ($id) => $id !== null)
      ->unique()
      ->values();
  }

  /**
   * @return array{0: list<string>, 1: array<string, array>} Ordered ids and hits keyed by id (first hit wins).
   */
  protected function indexHits($results, $model): array
  {
    $keyName = $this->sourceKeyName($model);
    $hitsById = [];

    foreach ($results['hits']['hits'] ?? [] as $hit) {
      $id = $this->hitId((array) $hit, $keyName);

      if ($id !== null && ! isset($hitsById[$id])) {
        $hitsById[$id] = $hit;
      }
    }

    return [array_map('strval', array_keys($hitsById)), $hitsById];
  }

  protected function withHitMetadata($model, array $hit)
  {
    foreach ($hit as $key => $value) {
      if (str_starts_with($key, '_')) {
        $model->withScoutMetadata($key, $value);
      }
    }

    return $model;
  }

  /**
   * A 404 from the index itself (JSON body, e.g. `"result":"not_found"`), as opposed to a
   * plain-text "404 page not found" from a route the service does not provide.
   */
  protected function isNotFound(Response $response): bool
  {
    return $response->status() === 404 && is_array($response->json());
  }

  /**
   * A short, payload-free description of a failed response.
   */
  protected function describeResponse(Response $response): string
  {
    $fallback = 'HTTP ' . $response->status();

    if ($response->status() === 404 && ! is_array($response->json())) {
      $fallback .= ' (endpoint not available)';
    }

    return $this->describeError($response->json('error'), $fallback);
  }

  protected function describeError($error, string $fallback): string
  {
    if (is_array($error)) {
      $error = trim(($error['type'] ?? '') . ': ' . ($error['reason'] ?? ''), ': ');
    }

    return is_string($error) && $error !== '' ? Str::limit($error, 300) : $fallback;
  }
}
