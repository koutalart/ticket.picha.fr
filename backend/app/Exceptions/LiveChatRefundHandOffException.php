<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;

/**
 * The refund cannot be done automatically (cap, expired check, order changed): a person takes over.
 * The message is the reason shown to the organizer, never to the visitor.
 */
class LiveChatRefundHandOffException extends Exception {}
