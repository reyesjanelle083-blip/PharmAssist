<?php

namespace App\Exceptions;

use RuntimeException;

/** Token is missing a valid signature, is malformed, or lacks required claims. */
class InvalidTokenException extends RuntimeException {}
