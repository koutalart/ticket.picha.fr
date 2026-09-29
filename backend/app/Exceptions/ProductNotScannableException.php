<?php

namespace HiEvents\Exceptions;

use Exception;

/**
 * D11 (PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md): a Box Office sale was
 * attempted for a product not attached to any active check-in list — it
 * would be rejected at the door. Blocked before the sale, not after.
 */
class ProductNotScannableException extends Exception
{
}
