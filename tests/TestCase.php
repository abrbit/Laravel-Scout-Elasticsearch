<?php

namespace Abrbit\Scout\Engines\Tests;

use Abrbit\Scout\Engines\AbrbitSearchEngine;
use Abrbit\Scout\Engines\AbrbitSearchServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\EngineManager;
use Laravel\Scout\ScoutServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
  protected const URL = 'https://search.test';

  protected const TOKEN = 'secret-token-123';

  protected function getPackageProviders($app)
  {
    return [ScoutServiceProvider::class, AbrbitSearchServiceProvider::class];
  }

  protected function defineEnvironment($app)
  {
    $app['config']->set('database.default', 'testing');
    $app['config']->set('database.connections.testing', [
      'driver' => 'sqlite',
      'database' => ':memory:',
      'prefix' => '',
    ]);
    $app['config']->set('scout.driver', 'abrbit');
    $app['config']->set('services.search', [
      'url' => self::URL . '/',
      'token' => self::TOKEN,
      'retry_sleep_ms' => 0,
    ]);
  }

  protected function defineDatabaseMigrations()
  {
    Schema::create('products', function (Blueprint $table) {
      $table->id();
      $table->string('title');
      $table->string('sku')->nullable();
      $table->string('image_status')->nullable();
      $table->boolean('is_hidden')->default(false);
    });
  }

  protected function setUp(): void
  {
    parent::setUp();

    Http::preventStrayRequests();
  }

  protected function engine(): AbrbitSearchEngine
  {
    return $this->app->make(EngineManager::class)->engine('abrbit');
  }

  /**
   * Decode an NDJSON request body into its lines.
   */
  protected function ndjson(Request $request): array
  {
    return array_map(
      fn ($line) => json_decode($line, true),
      array_values(array_filter(explode("\n", $request->body()), 'strlen'))
    );
  }

  protected function bulkResponse(array $items, bool $errors = false): array
  {
    return ['took' => 3, 'errors' => $errors, 'items' => $items];
  }

  protected function hit(string $id, array $source, float $score = 1.0): array
  {
    return ['_index' => 'products', '_id' => $id, '_score' => $score, '_source' => $source];
  }
}
