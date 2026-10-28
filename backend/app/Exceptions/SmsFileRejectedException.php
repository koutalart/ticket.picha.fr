<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;

/**
 * The central SMS queue refused the request (invalid SMS, unknown line, too many SMS). A list is refused as a whole.
 */
class SmsFileRejectedException extends Exception {}
