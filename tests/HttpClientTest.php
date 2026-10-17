<?php

namespace Abrbit\Scout\Engines\Tests;

use Abrbit\Scout\Engines\Exceptions\AbrbitIndexingException;
use Abrbit\Scout\Engines\Exceptions\AbrbitSearchException;
use Abrbit\Scout\Engines\Tests\Fixtures\Product;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class HttpClientTest extends TestCase
{
  protected function emptyResult(): array
  {
    return ['hits' => ['total' => ['value' => 0], 'hits' => []]];
  }

  public function test_default_timeouts_are_applied(): void
  {
    $options = [];
    Http::fake(function (Request $r, array $o) use (&$options) {
      $options[] = $o;

      return Http::response($this->emptyResult());
    });

    Product::search('mug')->raw();

    $this->assertSame(10, $options[0]['timeout']);
    $this->assertSame(3, $options[0]['connect_timeout']);
  }

  public function test_configured_timeouts_are_applied_to_every_operation(): void
  {
    config(['services.search.timeout' => 25, 'services.search.connect_timeout' => 1]);

    $options = [];
    Http::fake(function (Request $r, array $o) use (&$options) {
      $options[] = [$o['timeout'], $o['connect_timeout']];

      return Http::response(['items' => []] + $this->emptyResult());
    });

    $product = (new Product)->forceFill(['id' => 1, 'title' => 'Mug']);

    Product::search('mug')->raw();
    Product::search('mug')->searchSource();
    $this->engine()->update($product->newCollection([$product]));
    $this->engine()->delete($product->newCollection([$product]));
    $this->engine()->flush($product);
    $this->engine()->createIndex('products');
    $this->engine()->deleteIndex('products');
    config(['services.search.bulk' => true]);
    $this->engine()->update($product->newCollection([$product]));

    $this->assertCount(8, $options);
    $this->assertSame(array_fill(0, 8, [25, 1]), $options);
  }

  public function test_base_url_and_token_are_sent(): void
  {
    Http::fake(['*' => Http::response($this->emptyResult())]);

    Product::search('mug')->raw();

    Http::assertSent(fn (Request $r) => $r->url() === self::URL . '/indexes/products/_search'
      && $r->hasHeader('Authorization', 'Bearer ' . self::TOKEN));
  }

  public static function retryableStatuses(): array
  {
    return [[429], [500], [502], [503]];
  }

  #[DataProvider('retryableStatuses')]
  public function test_retries_on_429_and_5xx(int $status): void
  {
    Http::fake(['*' => Http::sequence()->push('', $status)->push($this->emptyResult())]);

    $this->assertSame($this->emptyResult(), Product::search('mug')->raw());

    Http::assertSentCount(2);
  }

  public static function nonRetryableStatuses(): array
  {
    return [[400], [401], [403], [404], [422]];
  }

  #[DataProvider('nonRetryableStatuses')]
  public function test_does_not_retry_client_errors(int $status): void
  {
    Http::fake(['*' => Http::response('', $status)]);

    try {
      Product::search('mug')->raw();
      $this->fail('Expected AbrbitSearchException');
    } catch (AbrbitSearchException $e) {
      $this->assertStringContainsString("HTTP {$status}", $e->getMessage());
    }

    Http::assertSentCount(1);
  }

  public function test_retries_on_connection_errors(): void
  {
    $attempts = 0;
    Http::fake(function (Request $r) use (&$attempts) {
      return ++$attempts === 1
        ? Create::rejectionFor(new ConnectException('Connection timed out', $r->toPsrRequest()))
        : Http::response($this->emptyResult());
    });

    Product::search('mug')->raw();

    $this->assertSame(2, $attempts);
  }

  public function test_retry_times_is_configurable_and_bounded(): void
  {
    config(['services.search.retry_times' => 5]);
    Http::fake(['*' => Http::response('', 500)]);

    try {
      Product::search('mug')->raw();
    } catch (AbrbitSearchException) {
    }

    Http::assertSentCount(5);
  }

  public function test_search_outage_throws_a_clear_exception_without_the_token(): void
  {
    $attempts = 0;
    Http::fake(function (Request $r) use (&$attempts) {
      $attempts++;

      return Create::rejectionFor(new ConnectException('Connection refused', $r->toPsrRequest()));
    });

    try {
      Product::search('mug')->get();
      $this->fail('Expected AbrbitSearchException');
    } catch (AbrbitSearchException $e) {
      $this->assertSame('Abrbit search request on index [products] failed: connection error (ConnectionException).', $e->getMessage());
      $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
      $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
    }

    $this->assertSame(3, $attempts);
  }

  public function test_all_search_methods_throw_on_http_errors(): void
  {
    Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

    foreach ([
      fn () => Product::search('mug')->raw(),
      fn () => Product::search('mug')->paginate(10),
      fn () => Product::search('mug')->searchSource(),
      fn () => Product::search('mug')->getSource(),
    ] as $call) {
      try {
        $call();
        $this->fail('Expected AbrbitSearchException');
      } catch (AbrbitSearchException $e) {
        $this->assertStringContainsString('HTTP 500', $e->getMessage());
      }
    }
  }

  public function test_legacy_search_error_behaviour_can_be_restored(): void
  {
    config(['services.search.throw_on_search_errors' => false]);
    Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

    $this->assertSame(['error' => 'boom'], Product::search('mug')->raw());
    $this->assertSame([], Product::search('mug')->getSource());
    $this->assertCount(0, Product::search('mug')->get());
    $this->assertSame(
      ['data' => [], 'total' => 0, 'per_page' => 20, 'current_page' => 1],
      Product::search('mug')->searchSource()
    );
  }

  public function test_flush_ignores_missing_index_but_index_admin_errors_throw(): void
  {
    Http::fake([
      '*/indexes/products/documents' => Http::response('', 404),
      '*' => Http::response(['error' => ['type' => 'resource_already_exists_exception', 'reason' => 'index exists']], 400),
    ]);

    $this->engine()->flush(new Product);

    $this->expectException(AbrbitIndexingException::class);
    $this->expectExceptionMessage('resource_already_exists_exception');

    $this->engine()->createIndex('products');
  }
}
