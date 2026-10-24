<?php

namespace App\Exceptions;

use RuntimeException;

/** Token signature is valid but the token has expired. */
class TokenExpiredException extends RuntimeException {}
