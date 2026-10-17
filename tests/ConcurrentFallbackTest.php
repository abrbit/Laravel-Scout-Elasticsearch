<?php

namespace Abrbit\Scout\Engines\Tests;

use Abrbit\Scout\Engines\Exceptions\AbrbitIndexingException;
use Abrbit\Scout\Engines\Tests\Fixtures\Product;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Bulk disabled (the default): one request per document, sent concurrently with Http::pool.
 */
class ConcurrentFallbackTest extends TestCase
{
  protected function products(int $count, array $hidden = []): Collection
  {
    return new Collection(array_map(
      fn ($id) => (new Product)->forceFill(['id' => $id, 'title' => "Product {$id}", 'is_hidden' => in_array($id, $hidden)]),
      range(1, $count)
    ));
  }

  public function test_bulk_is_disabled_by_default_and_documents_are_posted_individually(): void
  {
    Http::fake(['*' => Http::response(['result' => 'created'], 201)]);

    $this->engine()->update($this->products(25, hidden: [3]));

    Http::assertSentCount(24);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '_bulk'));
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
      && $r->url() === self::URL . '/indexes/products/documents'
      && $r->data() === ['id' => 1, 'title' => 'Product 1']
      && $r->hasHeader('Authorization', 'Bearer ' . self::TOKEN));
  }

  public function test_explicit_ids_flag_puts_documents_by_scout_key(): void
  {
    config(['services.search.explicit_ids' => true]);
    Http::fake(['*' => Http::response(['result' => 'updated'])]);

    $this->engine()->update($this->products(2));

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === self::URL . '/indexes/products/documents/2');
    Http::assertSentCount(2);
  }

  public function test_every_response_is_checked(): void
  {
    config(['services.search.concurrency' => 2, 'services.search.explicit_ids' => true]);
    Http::fake([
      '*' => function (Request $r) {
        return match ((int) basename($r->url())) {
          2 => Http::response(['error' => ['type' => 'mapper_parsing_exception', 'reason' => 'bad title']], 400),
          5 => Http::response('', 500),
          default => Http::response(['result' => 'created'], 201),
        };
      },
    ]);

    try {
      $this->engine()->update($this->products(5));
      $this->fail('Expected AbrbitIndexingException');
    } catch (AbrbitIndexingException $e) {
      $this->assertSame(['2', '5'], $e->failedIds());
      $this->assertSame('mapper_parsing_exception: bad title', $e->failures()[2]);
      $this->assertSame('HTTP 500', $e->failures()[5]);
    }

    // 3 succeed once, 2 is not retried (4xx), 5 is retried up to the default 3 attempts.
    Http::assertSentCount(3 + 1 + 3);
  }

  public function test_pool_requests_are_retried_on_server_errors(): void
  {
    config(['services.search.explicit_ids' => true]);
    Http::fake(['*' => Http::sequence()->push('', 503)->push(['result' => 'created'], 201)]);

    $this->engine()->update($this->products(1));

    Http::assertSentCount(2);
  }

  public function test_pool_requests_are_retried_on_connection_errors(): void
  {
    config(['services.search.explicit_ids' => true]);

    $attempts = 0;
    Http::fake(function (Request $r) use (&$attempts) {
      return ++$attempts < 3
        ? Create::rejectionFor(new ConnectException('Connection reset', $r->toPsrRequest()))
        : Http::response(['result' => 'created'], 201);
    });

    $this->engine()->update($this->products(1));

    $this->assertSame(3, $attempts);
  }

  public static function nonRetryablePostFailures(): array
  {
    return [
      'HTTP 500' => [500],
      'HTTP 503' => [503],
      'HTTP 429' => [429],
    ];
  }

  #[DataProvider('nonRetryablePostFailures')]
  public function test_post_without_explicit_id_is_not_retried_on_server_errors(int $status): void
  {
    Http::fake(['*' => Http::sequence()->push('', $status)->push(['result' => 'created'], 201)]);

    try {
      $this->engine()->update($this->products(1));
      $this->fail('Expected AbrbitIndexingException');
    } catch (AbrbitIndexingException $e) {
      $this->assertSame(['1' => "HTTP {$status}"], $e->failures());
    }

    Http::assertSentCount(1);
  }

  public static function connectionFailures(): array
  {
    return [
      // [cURL handler context, retried?]
      'could not resolve host' => [['errno' => CURLE_COULDNT_RESOLVE_HOST, 'pretransfer_time' => 0.0], true],
      'connection refused' => [['errno' => CURLE_COULDNT_CONNECT, 'pretransfer_time' => 0.0], true],
      'connect timeout' => [['errno' => CURLE_OPERATION_TIMEOUTED, 'connect_time' => 0.0, 'pretransfer_time' => 0.0], true],
      'read timeout after sending' => [['errno' => CURLE_OPERATION_TIMEOUTED, 'connect_time' => 0.01, 'pretransfer_time' => 0.02], false],
      'connection reset after sending' => [['errno' => CURLE_RECV_ERROR, 'pretransfer_time' => 0.02], false],
      'no handler context' => [[], false],
    ];
  }

  #[DataProvider('connectionFailures')]
  public function test_post_without_explicit_id_is_retried_only_when_the_request_was_never_sent(array $context, bool $retried): void
  {
    $attempts = 0;
    Http::fake(function (Request $r) use (&$attempts, $context) {
      return ++$attempts === 1
        ? Create::rejectionFor(new ConnectException('cURL error', $r->toPsrRequest(), null, $context))
        : Http::response(['result' => 'created'], 201);
    });

    if (! $retried) {
      $this->expectException(AbrbitIndexingException::class);
    }

    try {
      $this->engine()->update($this->products(1));
    } finally {
      $this->assertSame($retried ? 2 : 1, $attempts);
    }
  }

  public function test_delete_is_still_retried_on_server_errors_without_explicit_ids(): void
  {
    Http::fake(['*' => Http::sequence()->push('', 503)->push(['result' => 'deleted'])]);

    $this->engine()->delete($this->products(1));

    Http::assertSentCount(2);
  }

  public function test_delete_ignores_404_and_reports_other_failures(): void
  {
    Http::fake([
      '*/documents/1' => Http::response(['result' => 'deleted']),
      '*/documents/2' => Http::response(['result' => 'not_found'], 404),
      '*/documents/3' => Http::response(['error' => 'forbidden'], 403),
    ]);

    try {
      $this->engine()->delete($this->products(3));
      $this->fail('Expected AbrbitIndexingException');
    } catch (AbrbitIndexingException $e) {
      $this->assertSame(['3'], $e->failedIds());
      $this->assertSame('forbidden', $e->failures()[3]);
    }

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::URL . '/indexes/products/documents/2');
  }

  public function test_delete_of_missing_documents_succeeds(): void
  {
    Http::fake(['*' => Http::response(['result' => 'not_found'], 404)]);

    $this->engine()->delete($this->products(2));

    Http::assertSentCount(2);
  }

  public function test_delete_reports_a_missing_endpoint_instead_of_treating_it_as_deleted(): void
  {
    // Plain-text 404 from the service router: the route does not exist.
    Http::fake(['*' => Http::response('404 page not found', 404, ['Content-Type' => 'text/plain'])]);

    try {
      $this->engine()->delete($this->products(1));
      $this->fail('Expected AbrbitIndexingException');
    } catch (AbrbitIndexingException $e) {
      $this->assertSame(['1' => 'HTTP 404 (endpoint not available)'], $e->failures());
    }
  }
}
