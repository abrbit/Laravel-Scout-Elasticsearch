<?php

namespace Abrbit\Scout\Engines\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Product extends Model
{
  use Searchable;

  public $timestamps = false;

  protected $guarded = [];

  public function searchableAs()
  {
    return 'products';
  }

  public function getSearchableFields(): array
  {
    return ['title'];
  }

  public function toSearchableArray()
  {
    if ($this->is_hidden) {
      return [];
    }

    return [
      'id' => $this->getKey(),
      'title' => $this->title,
    ];
  }
}
