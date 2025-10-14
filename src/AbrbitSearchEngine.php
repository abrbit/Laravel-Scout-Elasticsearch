<?php

namespace Abrbit\Scout\Engines;

use Illuminate\Support\Facades\Http;
use Laravel\Scout\Engines\Engine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;

class AbrbitSearchEngine extends Engine
{

  protected function indexName($model)
  {
    return $model->searchableAs();
  }

  /**
   * Update / index the given models in the search engine.
   */
  public function update($models)
  {
      foreach ($models as $model) {
          $payload = $model->toSearchableArray();

          Http::withToken(config('services.search.token'))
              ->post(
                  config('services.search.url') . "/indexes/" . $model->searchableAs() . "/documents",
                  $payload
              );
      }
  }

  /**
   * Remove the given models from the search index.
   */
  public function delete($models)
  {
    foreach ($models as $model) {
      Http::withToken(config('services.search.token'))
        ->delete(
          config('services.search.url') . "/indexes/" . $model->searchableAs() . "/documents/" . $model->getKey()
        );
    }
  }

  /**
   * Perform the given search.
   */
  public function search(Builder $builder)
  {
    $fields = method_exists($builder->model, 'getSearchableFields')
      ? $builder->model->getSearchableFields()
      : ($builder->model->searchableFields ?? ['id']); // fallback for older models


      $query = [
      'multi_match' => [
        'query' => $builder->query,
        'fields' => $fields,
      ],
    ];

    if ($builder->callback) {
      $query = call_user_func($builder->callback, $query, $builder);
    }

    $response = Http::withToken(config('services.search.token'))
      ->post(config('services.search.url') . "/indexes/" . $builder->model->searchableAs() . "/_search", [
        'query' => $query,
      ]);

    return $response->json();
  }

  /**
   * Paginate the given search.
   */
  public function paginate(Builder $builder, $perPage, $page)
  {

    $fields = method_exists($builder->model, 'getSearchableFields')
      ? $builder->model->getSearchableFields()
      : ['id'];


    $query = [
      'multi_match' => [
        'query' => $builder->query,
        'fields' => $fields,
      ],
    ];


    if ($builder->callback) {
      $query = call_user_func($builder->callback, $query, $builder);
    }

    $response = Http::withToken(config('services.search.token'))
      ->post(config('services.search.url') . "/indexes/" . $builder->model->searchableAs() . "/_search", [
        'from' => ($page - 1) * $perPage,
        'size' => $perPage,
        'query' => $query,
      ]);

    return $response->json();
  }

  /**
   * Map the given results to IDs.
   */
  public function mapIds($results)
  {
    return collect($results['hits']['hits'] ?? [])->pluck('id')->values();
  }

  /**
   * Map the given results to models.
   */
  public function map(Builder $builder, $results, $model)
  {
    if (empty($results['hits']['hits'])) {
      return Collection::make();
    }

    $keys = collect($results['hits']['hits'])->pluck('_source.id')->values()->all();

    $models = $model->whereIn($model->getKeyName(), $keys)
      ->get()
      ->keyBy($model->getKeyName());

    return Collection::make($results['hits']['hits'])->map(function ($hit) use ($models) {
      $id = $hit['_source']['id'];
      return $models[$id] ?? null;
    })->filter();
  }

  /**
   * Lazy map for large result sets.
   */
  public function lazyMap(Builder $builder, $results, $model)
  {
    if (empty($results['hits']['hits'])) {
      return LazyCollection::make([]);
    }

    $objectIds = collect($results['hits']['hits'])->pluck('_id')->values()->all();
    $objectIdPositions = array_flip($objectIds);

    return $model->queryScoutModelsByIds($builder, $objectIds)
      ->cursor()
      ->filter(fn($m) => in_array($m->getScoutKey(), $objectIds))
      ->map(function ($m) use ($results, $objectIdPositions) {
        $hit = collect($results['hits']['hits'])->firstWhere('_id', $m->getScoutKey());

        foreach ($hit as $key => $value) {
          if (substr($key, 0, 1) === '_') {
            $m->withScoutMetadata($key, $value);
          }
        }

        return $m;
      })
      ->sortBy(fn($m) => $objectIdPositions[$m->getScoutKey()])
      ->values();
  }

  /**
   * Get the total count from a raw result.
   */
  public function getTotalCount($results)
  {
    return $results['hits']['total']['value'] ?? 0;
  }

  /**
   * Flush all of the model's records from the index.
   */
  public function flush($model)
  {
    Http::withToken(config('services.search.token'))
      ->delete(config('services.search.url') . "/indexes/" . $model->searchableAs() . "/documents");
  }

  /**
   * Create a new index.
   */
  public function createIndex($name, array $options = [])
  {
    Http::withToken(config('services.search.token'))
      ->put(config('services.search.url') . "/indexes/" . $name, $options);
  }

  /**
   * Delete an index.
   */
  public function deleteIndex($name)
  {
    Http::withToken(config('services.search.token'))
      ->delete(config('services.search.url') . "/indexes/" . $name);
  }
}
