<?php

namespace Abrbit\Scout\Engines\Tests;

use Abrbit\Scout\Engines\Exceptions\AbrbitIndexingException;
use Abrbit\Scout\Engines\Tests\Fixtures\Product;
use Abrbit\Scout\Engines\Tests\Fixtures\SkuProduct;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;

class BulkIndexingTest extends TestCase
{
  protected function setUp(): void
  {
    parent::setUp();

    config(['services.search.bulk' => true]);
  }

  protected function products(int $count, array $hidden = []): Collection
  {
    return new Collection(array_map(
      fn ($id) => (new Product)->forceFill(['id' => $id, 'title' => "محصول {$id}", 'is_hidden' => in_array($id, $hidden)]),
      range(1, $count)
    ));
  }

  public function test_update_sends_ndjson_index_actions_in_chunks(): void
  {
    config(['services.search.bulk_size' => 2]);
    Http::fake(['*' => Http::response($this->bulkResponse([]))]);

    $this->engine()->update($this->products(5));

    Http::assertSentCount(3);

    $recorded = Http::recorded()->map(fn ($pair) => $pair[0]);
    $this->assertSame(
      [[1, 2], [3, 4], [5]],
      $recorded->map(fn (Request $r) => array_column(array_filter($this->ndjson($r), fn ($l) => isset($l['id'])), 'id'))->all()
    );

    /** @var Request $first */
    $first = $recorded->first();
    $this->assertSame('POST', $first->method());
    $this->assertSame(self::URL . '/indexes/products/_bulk', $first->url());
    $this->assertSame('application/x-ndjson', $first->header('Content-Type')[0]);
    $this->assertStringEndsWith("\n", $first->body());
    $this->assertStringContainsString('محصول 1', $first->body(), 'unicode is not escaped');
    $this->assertSame([
      ['index' => ['_id' => '1']],
      ['id' => 1, 'title' => 'محصول 1'],
      ['index' => ['_id' => '2']],
      ['id' => 2, 'title' => 'محصول 2'],
    ], $this->ndjson($first));
  }

  public function test_default_bulk_size_is_500(): void
  {
    Http::fake(['*' => Http::response($this->bulkResponse([]))]);

    $this->engine()->update($this->products(1001));

    Http::assertSentCount(3);
  }

  public function test_document_id_is_the_scout_key(): void
  {
    Http::fake(['*' => Http::response($this->bulkResponse([]))]);

    $this->engine()->update(new Collection([
      (new SkuProduct)->forceFill(['id' => 7, 'sku' => 'SKU/42', 'title' => 'Mug']),
    ]));

    Http::assertSent(fn (Request $r) => $this->ndjson($r)[0] === ['index' => ['_id' => 'SKU/42']]);
  }

  public function test_models_with_empty_payload_are_skipped(): void
  {
    Http::fake(['*' => Http::response($this->bulkResponse([]))]);

    $this->engine()->update($this->products(3, hidden: [2]));

    Http::assertSent(fn (Request $r) => array_column($this->ndjson($r), 'index') === [['_id' => '1'], ['_id' => '3']]);
  }

  public function test_nothing_is_sent_when_every_payload_is_empty(): void
  {
    Http::fake();

    $this->engine()->update($this->products(2, hidden: [1, 2]));
    $this->engine()->update(new Collection);

    Http::assertNothingSent();
  }

  public function test_bulk_item_errors_throw_with_the_failed_ids(): void
  {
    Http::fake(['*' => Http::response($this->bulkResponse([
      ['index' => ['_id' => '1', 'status' => 201, 'result' => 'created']],
      ['index' => ['_id' => '2', 'status' => 400, 'error' => ['type' => 'mapper_parsing_exception', 'reason' => 'failed to parse field [title]']]],
      ['index' => ['_id' => '3', 'status' => 429, 'error' => ['type' => 'es_rejected_execution_exception', 'reason' => 'rejected']]],
    ], errors: true))]);

    try {
      $this->engine()->update($this->products(3));
      $this->fail('Expected AbrbitIndexingException');
    } catch (AbrbitIndexingException $e) {
      $this->assertSame(['2', '3'], $e->failedIds());
      $this->assertSame('products', $e->index());
      $this->assertSame('mapper_parsing_exception: failed to parse field [title]', $e->failures()[2]);
      $this->assertStringContainsString('2 document(s) in index [products]', $e->getMessage());
      $this->assertStringContainsString('3 (es_rejected_execution_exception: rejected)', $e->getMessage());
    }
  }

  public function test_item_errors_in_one_chunk_do_not_stop_later_chunks(): void
  {
    config(['services.search.bulk_size' => 1]);
    Http::fake(['*' => Http::sequence()
      ->push($this->bulkResponse([['index' => ['_id' => '1', 'status' => 400, 'error' => ['type' => 'x', 'reason' => 'y']]]], true))
      ->push($this->bulkResponse([['index' => ['_id' => '2', 'status' => 201]]]))]);

    try {
      $this->engine()->update($this->products(2));
      $this->fail('Expected AbrbitIndexingException');
    } catch (AbrbitIndexingException $e) {
      $this->assertSame(['1'], $e->failedIds());
    }

    Http::assertSentCount(2);
  }

  public function test_failed_bulk_request_throws_listing_every_id_of_the_chunk(): void
  {
    config(['services.search.retry_times' => 2]);
    Http::fake(['*' => Http::response(['error' => ['type' => 'cluster_block_exception', 'reason' => 'read only']], 503)]);

    try {
      $this->engine()->update($this->products(3));
      $this->fail('Expected AbrbitIndexingException');
    } catch (AbrbitIndexingException $e) {
      $this->assertSame(['1', '2', '3'], $e->failedIds());
      $this->assertInstanceOf(RequestException::class, $e->getPrevious());
      $this->assertStringContainsString('cluster_block_exception', $e->getMessage());
    }

    Http::assertSentCount(2);
  }

  public function test_unexpected_bulk_response_is_a_failure(): void
  {
    Http::fake(['*' => Http::response(['ok' => true])]);

    $this->expectException(AbrbitIndexingException::class);

    $this->engine()->update($this->products(1));
  }

  public function test_errors_are_logged_without_payload_or_token_when_throwing_is_disabled(): void
  {
    config(['services.search.throw_on_index_errors' => false]);
    Http::fake(['*' => Http::response($this->bulkResponse([
      ['index' => ['_id' => '1', 'status' => 400, 'error' => ['type' => 'mapper_parsing_exception', 'reason' => 'bad']]],
    ], true))]);

    Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) {
      $logged = $message . json_encode($context, JSON_UNESCAPED_UNICODE);

      return $context['failed_ids'] === ['1']
        && ! str_contains($logged, 'محصول')
        && ! str_contains($logged, self::TOKEN);
    });

    $this->engine()->update($this->products(1));
  }

  public function test_delete_sends_bulk_delete_actions_and_ignores_not_found(): void
  {
    Http::fake(['*' => Http::response($this->bulkResponse([
      ['delete' => ['_id' => '1', 'status' => 200, 'result' => 'deleted']],
      ['delete' => ['_id' => '2', 'status' => 404, 'result' => 'not_found']],
    ]))]);

    $this->engine()->delete($this->products(2));

    Http::assertSent(fn (Request $r) => $r->url() === self::URL . '/indexes/products/_bulk'
      && $this->ndjson($r) === [['delete' => ['_id' => '1']], ['delete' => ['_id' => '2']]]);
  }

  public function test_delete_bulk_errors_other_than_not_found_throw(): void
  {
    Http::fake(['*' => Http::response($this->bulkResponse([
      ['delete' => ['_id' => '1', 'status' => 404, 'result' => 'not_found']],
      ['delete' => ['_id' => '2', 'status' => 503, 'error' => ['type' => 'unavailable_shards_exception', 'reason' => 'primary shard is not active']]],
    ], true))]);

    $this->expectException(AbrbitIndexingException::class);
    $this->expectExceptionMessage('1 document(s)');

    $this->engine()->delete($this->products(2));
  }

  public function test_custom_bulk_path(): void
  {
    config(['services.search.bulk_path' => '_bulk/{index}']);
    Http::fake(['*' => Http::response($this->bulkResponse([]))]);

    $this->engine()->update($this->products(1));

    Http::assertSent(fn (Request $r) => $r->url() === self::URL . '/_bulk/products');
  }

  public function test_partial_update_sends_update_actions_with_doc(): void
  {
    Http::fake(['*' => Http::response($this->bulkResponse([]))]);

    $products = $this->products(2)->each(fn ($p) => $p->image_status = "ready-{$p->id}");

    $this->engine()->partialUpdate($products, ['image_status', 'flag' => fn ($p) => $p->id * 10]);

    Http::assertSent(fn (Request $r) => $this->ndjson($r) === [
      ['update' => ['_id' => '1']],
      ['doc' => ['image_status' => 'ready-1', 'flag' => 10]],
      ['update' => ['_id' => '2']],
      ['doc' => ['image_status' => 'ready-2', 'flag' => 20]],
    ]);
  }

  public function test_partial_update_requires_bulk(): void
  {
    config(['services.search.bulk' => false]);
    Http::fake();

    $this->expectException(LogicException::class);

    $this->engine()->partialUpdate($this->products(1), ['title']);
  }
}
