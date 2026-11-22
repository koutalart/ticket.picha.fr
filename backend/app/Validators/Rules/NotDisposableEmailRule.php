<?php

namespace HiEvents\Validators\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses throwaway e-mail addresses (list from github.com/disposable-email-domains, plus domains seen in spam sign-ups).
 */
class NotDisposableEmailRule implements ValidationRule
{
    /** @var array<string, true>|null */
    private static ?array $domains = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! str_contains($value, '@')) {
            return;
        }

        if (self::isDisposable($value)) {
            $fail(__('Please use a permanent e-mail address. Temporary addresses are not accepted.'));
        }
    }

    public static function isDisposable(string $email): bool
    {
        $domain = strtolower(trim(substr($email, strrpos($email, '@') + 1), " .\t\n\r"));
        $domains = self::domains();
        $parts = explode('.', $domain);

        for ($i = 0; $i < count($parts) - 1; $i++) {
            if (isset($domains[implode('.', array_slice($parts, $i))])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private static function domains(): array
    {
        if (self::$domains === null) {
            $lines = file(base_path('data/disposable_email_domains.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            self::$domains = array_fill_keys(array_map('strtolower', array_map('trim', $lines)), true);
        }

        return self::$domains;
    }
}
