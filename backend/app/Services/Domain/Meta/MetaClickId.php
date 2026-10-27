<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Meta;

use Carbon\Carbon;

final class MetaClickId
{
    /**
     * The _fbc value, rebuilt from the fbclid of the ad click when the cookie is missing.
     */
    public static function fbc(?string $fbc, ?string $fbclid, ?int $clickedAtMs = null): ?string
    {
        if ($fbc !== null && $fbc !== '') {
            return $fbc;
        }

        if ($fbclid === null || ! preg_match('/^[A-Za-z0-9_\-.]{1,500}$/', $fbclid)) {
            return null;
        }

        return sprintf('fb.1.%d.%s', $clickedAtMs ?? Carbon::now()->getTimestampMs(), $fbclid);
    }
}
