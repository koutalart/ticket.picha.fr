<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;

/**
 * The central SMS queue could not be reached, timed out, refused the key or failed on its side.
 */
class SmsFileUnavailableException extends Exception {}
