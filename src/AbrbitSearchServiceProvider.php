<?php

namespace Abrbit\Scout\Engines;

use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Illuminate\Support\ServiceProvider;

class AbrbitSearchServiceProvider extends ServiceProvider
{
  public function boot()
  {
    resolve(EngineManager::class)->extend('abrbit', function () {
      return new AbrbitSearchEngine;
    });

      Builder::macro('getSource', function () {
          /** @var \Laravel\Scout\Builder $this */
          $engine = $this->engine();

          $results = $engine->search($this);

          return $engine->mapSource($results);
      });
  }
}
