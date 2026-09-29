<?php

namespace HiEvents\Exceptions;

use Exception;

/**
 * S1 (PICHA_BOX_OFFICE_SECURITY_FINDINGS.md): the price the Box Office
 * client believes it is charging did not match product_prices.price —
 * AC-2, strict equality, no tolerance.
 */
class BoxOfficePriceMismatchException extends Exception
{
}
