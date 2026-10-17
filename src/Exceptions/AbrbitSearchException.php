<?php

namespace Abrbit\Scout\Engines\Exceptions;

use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Thrown when a search request cannot be completed (HTTP error or connection failure).
 */
class AbrbitSearchException extends AbrbitException
{
  public static function fromThrowable(string $index, Throwable $e): self
  {
    $reason = $e instanceof RequestException
      ? 'HTTP ' . $e->response->status()
      : 'connection error (' . class_basename($e) . ')';

    return new self("Abrbit search request on index [{$index}] failed: {$reason}.", 0, $e);
  }
}
