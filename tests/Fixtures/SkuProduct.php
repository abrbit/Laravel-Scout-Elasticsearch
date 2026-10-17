<?php

namespace Abrbit\Scout\Engines\Tests\Fixtures;

/**
 * A product whose scout key is the SKU instead of the primary key.
 */
class SkuProduct extends Product
{
  protected $table = 'products';

  public function getScoutKey()
  {
    return $this->sku;
  }

  public function getScoutKeyType()
  {
    return 'string';
  }

  public function getScoutKeyName()
  {
    return 'products.sku';
  }

  public function toSearchableArray()
  {
    return ['sku' => $this->sku, 'title' => $this->title];
  }
}
