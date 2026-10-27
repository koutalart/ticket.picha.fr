<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\EmailProvider;

class EmailAddressParser
{
    /**
     * "Jo <jo@example.test>" or "jo@example.test" to ['email' => ..., 'name' => ...], null when no address is found.
     *
     * @return array{email: string, name: ?string}|null
     */
    public function parseOne(string $value): ?array
    {
        $value = trim($value);

        if (preg_match('/^(.*)<\s*([^<>\s]+@[^<>\s]+)\s*>\s*$/s', $value, $matches) === 1) {
            $name = trim(trim($matches[1]), "\"' ");

            return [
                'email' => mb_strtolower($matches[2]),
                'name' => $name !== '' ? mb_substr($name, 0, 150) : null,
            ];
        }

        $emails = $this->parseEmails($value);

        return $emails === [] ? null : ['email' => $emails[0], 'name' => null];
    }

    /**
     * @return array<int, string> every lower-cased address found in the value
     */
    public function parseEmails(string $value): array
    {
        preg_match_all('/[A-Za-z0-9._%+\-=]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $value, $matches);

        return array_values(array_unique(array_map('mb_strtolower', $matches[0])));
    }
}
