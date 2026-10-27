<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox;

use Illuminate\Config\Repository;

/**
 * Each thread gets its own reply address, e.g. r-42-1f0c9a7d3b5e8c2a4d6f@in.picha.fr. The signature binds the
 * thread to its organizer with the application key, so a guessed or altered address never reaches another thread.
 */
class EmailReplyAddressService
{
    private const SIGNATURE_LENGTH = 20;

    public function __construct(
        private readonly Repository $config,
    ) {}

    public function isEnabled(): bool
    {
        return $this->domain() !== null && $this->key() !== '';
    }

    public function addressFor(int $threadId, int $organizerId): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        return sprintf('%s-%d-%s@%s', $this->prefix(), $threadId, $this->signature($threadId, $organizerId), $this->domain());
    }

    /**
     * Thread ids announced by reply addresses among the given ones. The signature still has to be checked against
     * the organizer of the thread with isValid().
     *
     * @param  array<int, string>  $addresses
     * @return array<int, array{thread_id: int, signature: string}>
     */
    public function extractCandidates(array $addresses): array
    {
        $domain = $this->domain();

        if ($domain === null) {
            return [];
        }

        $pattern = sprintf(
            '/^%s-(\d{1,18})-([0-9a-f]{%d})@%s$/',
            preg_quote($this->prefix(), '/'),
            self::SIGNATURE_LENGTH,
            preg_quote($domain, '/'),
        );

        $candidates = [];
        foreach ($addresses as $address) {
            if (preg_match($pattern, mb_strtolower(trim($address)), $matches) === 1) {
                $candidates[] = ['thread_id' => (int) $matches[1], 'signature' => $matches[2]];
            }
        }

        return $candidates;
    }

    public function isValid(int $threadId, int $organizerId, string $signature): bool
    {
        return $this->key() !== '' && hash_equals($this->signature($threadId, $organizerId), mb_strtolower($signature));
    }

    private function signature(int $threadId, int $organizerId): string
    {
        return substr(hash_hmac('sha256', 'email-thread|'.$threadId.'|'.$organizerId, $this->key()), 0, self::SIGNATURE_LENGTH);
    }

    private function domain(): ?string
    {
        $domain = mb_strtolower(trim((string) $this->config->get('services.email_inbox.reply_domain')));

        return $domain !== '' ? $domain : null;
    }

    private function prefix(): string
    {
        $prefix = mb_strtolower(trim((string) $this->config->get('services.email_inbox.reply_local_prefix', 'r')));

        return preg_match('/^[a-z0-9]{1,20}$/', $prefix) === 1 ? $prefix : 'r';
    }

    private function key(): string
    {
        return (string) $this->config->get('app.key');
    }
}
