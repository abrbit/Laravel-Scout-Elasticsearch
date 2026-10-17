<?php

namespace Abrbit\Scout\Engines\Exceptions;

use Throwable;

/**
 * Thrown when one or more documents could not be written to (or removed from) the index.
 *
 * Throwing lets Scout's queued jobs fail and be retried by the queue worker.
 */
class AbrbitIndexingException extends AbrbitException
{
  /** How many failures are spelled out in the exception message. */
  protected const MESSAGE_LIMIT = 10;

  /**
   * @param  array<string, string>  $failures  Document id => failure reason.
   */
  public function __construct(
    string $message,
    protected string $index,
    protected array $failures,
    ?Throwable $previous = null,
  ) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * @param  array<string, string>  $failures
   */
  public static function forFailures(string $operation, string $index, array $failures, ?Throwable $previous = null): self
  {
    $listed = [];
    foreach (array_slice($failures, 0, self::MESSAGE_LIMIT, true) as $id => $reason) {
      $listed[] = "{$id} ({$reason})";
    }

    $more = count($failures) > self::MESSAGE_LIMIT
      ? ' and ' . (count($failures) - self::MESSAGE_LIMIT) . ' more'
      : '';

    return new self(
      sprintf(
        'Abrbit %s failed for %d document(s) in index [%s]: %s%s.',
        $operation,
        count($failures),
        $index,
        implode(', ', $listed),
        $more
      ),
      $index,
      $failures,
      $previous
    );
  }

  public function index(): string
  {
    return $this->index;
  }

  /**
   * @return array<string, string> Document id => failure reason.
   */
  public function failures(): array
  {
    return $this->failures;
  }

  /**
   * @return list<string>
   */
  public function failedIds(): array
  {
    return array_map('strval', array_keys($this->failures));
  }
}
