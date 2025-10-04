<?php

namespace Abrbit\Scout\Engines;

use Laravel\Scout\EngineManager;
use Illuminate\Support\ServiceProvider;

class AbrbitSearchServiceProvider extends ServiceProvider
{
  public function boot()
  {
    resolve(EngineManager::class)->extend('abrbit', function () {
      return new AbrbitSearchEngine;
    });
  }
}
