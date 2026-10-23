<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Translation;

use Illuminate\Translation\MessageSelector;

/**
 * Plural rules of a variant locale (fr_TU) are the ones of its parent (fr).
 */
class FallbackLocaleMessageSelector extends MessageSelector
{
    /**
     * @param  array<string, string>  $parents  variant locale => parent locale
     */
    public function __construct(private readonly array $parents) {}

    public function getPluralIndex($locale, $number)
    {
        return parent::getPluralIndex($this->parents[$locale] ?? $locale, $number);
    }
}
