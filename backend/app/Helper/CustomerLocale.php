<?php

declare(strict_types=1);

namespace HiEvents\Helper;

final class CustomerLocale
{
    public static function get(): string
    {
        return config('app.customer_locale');
    }
}
