<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A 409 that is safe to retry: the row was busy with another transaction
 * (lock timeout / deadlock / serialization failure), nothing was written.
 * Unlike other 409s it must never flip an action to failed.
 */
final class RetryableConflict extends ConflictHttpException {}
