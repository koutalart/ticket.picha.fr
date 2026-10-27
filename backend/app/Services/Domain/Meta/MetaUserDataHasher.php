<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Meta;

use HiEvents\Helper\SmsPhoneNormalizer;
use Illuminate\Support\Str;

class MetaUserDataHasher
{
    public function email(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $this->hash($email) : null;
    }

    public function name(?string $name): ?string
    {
        $name = Str::lower(trim((string) $name));
        $name = preg_replace('/[^\p{L}\p{M}]+/u', '', $name);

        return $name === '' || $name === null ? null : $this->hash($name);
    }

    /**
     * Digits only with the country code, as Meta expects (0639... becomes 262639...).
     */
    public function phone(?string $phone): ?string
    {
        $normalized = $phone === null ? null : SmsPhoneNormalizer::normalize($phone);

        return $normalized === null ? null : $this->hash(ltrim($normalized, '+'));
    }

    public function countryCode(?string $countryCode): ?string
    {
        $countryCode = Str::lower(trim((string) $countryCode));

        return preg_match('/^[a-z]{2}$/', $countryCode) ? $this->hash($countryCode) : null;
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
