<?php

declare(strict_types=1);

namespace HiEvents\Helper;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;

final class CustomerLocale
{
    /**
     * French addressing customers with « tu ». Strings missing from lang/fr_TU.json fall back to lang/fr.json.
     */
    public const FRENCH_TUTOIEMENT = 'fr_TU';

    public const FALLBACKS = [self::FRENCH_TUTOIEMENT => 'fr'];

    public static function get(): string
    {
        return config('app.customer_locale');
    }

    public static function forAddressForm(CustomerAddressForm|string|null $addressForm): string
    {
        $base = self::get();
        $value = $addressForm instanceof CustomerAddressForm ? $addressForm->value : $addressForm;

        if ($value === CustomerAddressForm::TU->value && str_starts_with(strtolower($base), 'fr')) {
            return self::FRENCH_TUTOIEMENT;
        }

        return $base;
    }

    /**
     * The locale to use for dates, numbers and plural rules, which do not depend on the address form.
     */
    public static function formattingLocale(string $locale): string
    {
        return self::FALLBACKS[$locale] ?? $locale;
    }

    public static function isTutoiement(?string $locale): bool
    {
        return $locale === self::FRENCH_TUTOIEMENT;
    }
}
