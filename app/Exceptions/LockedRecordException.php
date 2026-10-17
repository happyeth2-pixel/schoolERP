<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when something tries to change a record that is locked in its current state (e.g. published marks). */
class LockedRecordException extends RuntimeException
{
}
