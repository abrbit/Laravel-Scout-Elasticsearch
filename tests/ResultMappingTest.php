<?php

namespace Abrbit\Scout\Engines\Tests;

use Abrbit\Scout\Engines\Tests\Fixtures\Product;
use Abrbit\Scout\Engines\Tests\Fixtures\SkuProduct;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ResultMappingTest extends TestCase
{
  protected function setUp(): void
  {
    parent::setUp();

    DB::table('products')->insert([
      ['id' => 1, 'title' => 'Mug', 'sku' => 'SKU-1'],
      ['id' => 2, 'title' => 'Plate', 'sku' => 'SKU-2'],
      ['id' => 3, 'title' => 'Bowl', 'sku' => 'SKU-3'],
    ]);
  }

  /**
   * Hits in relevance order 3, 1, 2 plus one hit (99) that no longer exists in the database.
   */
  protected function results(): array
  {
    return [
      'took' => 2,
      'timed_out' => false,
      'hits' => [
        'total' => ['value' => 4, 'relation' => 'eq'],
        'max_score' => 3.2,
        'hits' => [
          $this->hit('3', ['id' => 3, 'title' => 'Bowl'], 3.2),
          $this->hit('99', ['id' => 99, 'title' => 'Deleted'], 2.5),
          $this->hit('1', ['id' => 1, 'title' => 'Mug'], 2.1),
          $this->hit('2', ['id' => 2, 'title' => 'Plate'], 1.0),
        ],
      ],
    ];
  }

  public function test_map_ids_preserves_hit_order(): void
  {
    $this->assertSame(['3', '99', '1', '2'], $this->engine()->mapIds($this->results())->all());
    $this->assertSame([], $this->engine()->mapIds(['hits' => ['hits' => []]])->all());
  }

  public function test_map_returns_models_in_hit_order_with_metadata(): void
  {
    $models = $this->engine()->map(Product::search('x'), $this->results(), new Product);

    $this->assertSame([3, 1, 2], $models->modelKeys());
    $this->assertSame([0, 1, 2], $models->keys()->all());
    $this->assertSame(3.2, $models->first()->scoutMetadata()['_score']);
  }

  public function test_lazy_map_returns_models_in_hit_order(): void
  {
    $models = $this->engine()->lazyMap(Product::search('x'), $this->results(), new Product);

    $this->assertSame([3, 1, 2], $models->map->getKey()->all());
    $this->assertSame('1', $models->all()[1]->scoutMetadata()['_id']);
  }

  public function test_empty_results(): void
  {
    $empty = ['hits' => ['total' => ['value' => 0], 'hits' => []]];

    $this->assertCount(0, $this->engine()->map(Product::search('x'), $empty, new Product));
    $this->assertCount(0, $this->engine()->lazyMap(Product::search('x'), $empty, new Product));
    $this->assertSame(0, $this->engine()->getTotalCount($empty));
  }

  public function test_legacy_documents_with_auto_generated_ids_map_through_source(): void
  {
    $results = ['hits' => ['total' => ['value' => 3], 'hits' => [
      $this->hit('Swv5oJkBk5ZOaUeK3x_O', ['id' => 2, 'title' => 'Plate']),
      $this->hit('Ab12oJkBk5ZOaUeK9z_P', ['id' => 1, 'title' => 'Mug']),
      $this->hit('Dup0oJkBk5ZOaUeK9z_Q', ['id' => 2, 'title' => 'Plate (duplicate)']),
    ]]];

    $this->assertSame(['2', '1'], $this->engine()->mapIds($results)->all());
    $this->assertSame([2, 1], $this->engine()->map(Product::search('x'), $results, new Product)->modelKeys());
    $this->assertSame([2, 1], $this->engine()->lazyMap(Product::search('x'), $results, new Product)->map->getKey()->all());
  }

  public function test_id_falls_back_to_underscore_id_when_source_lacks_the_key(): void
  {
    $results = ['hits' => ['hits' => [
      $this->hit('2', ['title' => 'Plate']),
      $this->hit('1', ['title' => 'Mug']),
    ]]];

    $this->assertSame(['2', '1'], $this->engine()->mapIds($results)->all());
    $this->assertSame([2, 1], $this->engine()->map(Product::search('x'), $results, new Product)->modelKeys());
    $this->assertSame([2, 1], $this->engine()->lazyMap(Product::search('x'), $results, new Product)->map->getKey()->all());
  }

  public function test_custom_scout_key_is_used_for_mapping(): void
  {
    $results = ['hits' => ['hits' => [
      $this->hit('SKU-2', ['sku' => 'SKU-2', 'title' => 'Plate']),
      $this->hit('SKU-3', ['sku' => 'SKU-3', 'title' => 'Bowl']),
    ]]];

    $this->assertSame(['SKU-2', 'SKU-3'], $this->engine()->map(SkuProduct::search('x'), $results, new SkuProduct)->pluck('sku')->all());
    $this->assertSame(['SKU-2', 'SKU-3'], $this->engine()->lazyMap(SkuProduct::search('x'), $results, new SkuProduct)->pluck('sku')->all());
  }

  public function test_builder_get_keys_and_paginate_end_to_end(): void
  {
    Http::fake(['*' => Http::response($this->results())]);

    $this->assertSame([3, 1, 2], Product::search('x')->get()->modelKeys());
    $this->assertSame(['3', '99', '1', '2'], Product::search('x')->keys()->all());

    $page = Product::search('x')->paginate(2, 'page', 2);
    $this->assertSame(4, $page->total());

    Http::assertSent(fn (Request $r) => ($r->data()['from'] ?? null) === 2 && $r->data()['size'] === 2);
  }

  public function test_get_source_and_search_source_macros_keep_their_shape(): void
  {
    Http::fake(['*' => Http::response($this->results())]);

    $this->assertSame(
      [['id' => 3, 'title' => 'Bowl'], ['id' => 99, 'title' => 'Deleted'], ['id' => 1, 'title' => 'Mug'], ['id' => 2, 'title' => 'Plate']],
      Product::search('x')->getSource()
    );

    $this->assertSame([
      'data' => array_column($this->results()['hits']['hits'], '_source'),
      'total' => 4,
      'per_page' => 15,
      'current_page' => 2,
    ], Product::search('ab')->where('status', 'active')->searchSource(15, 2));

    Http::assertSent(fn (Request $r) => $r->data() === [
      'from' => 15,
      'size' => 15,
      'query' => ['bool' => [
        'must' => ['query_string' => ['query' => '*ab*', 'fields' => ['title']]],
        'filter' => [['term' => ['status' => 'active']]],
      ]],
    ]);
  }

  public function test_search_query_body_is_unchanged(): void
  {
    Http::fake(['*' => Http::response($this->results())]);

    Product::search('mug')->where('brand', 5)->options(['custom' => ['track_total_hits' => true]])->raw();

    Http::assertSent(fn (Request $r) => $r->data() === [
      'query' => ['bool' => [
        'must' => ['multi_match' => ['query' => 'mug', 'fields' => ['title']]],
        'filter' => [['term' => ['brand' => 5]]],
      ]],
      'track_total_hits' => true,
    ]);
  }
}
