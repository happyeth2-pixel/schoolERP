<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a record must not be deleted (it has history depending on it, or is never deleted at all). */
class DeletionBlockedException extends RuntimeException
{
}
