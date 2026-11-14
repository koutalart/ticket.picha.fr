<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\UneAnicka;

use HiEvents\Services\Infrastructure\UneAnicka\DTO\UneAnickaDecisionDTO;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks the single decision point of Anicka (une_anicka, behind the central SMS queue) whether an automatic reply may
 * be written to a person: no two systems answer the same person, nobody answers over a human. The question is asked
 * before anything is recorded or mirrored. When the router cannot be reached, the reply is allowed (and logged).
 */
class UneAnickaClient
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('services.une_anicka.enabled', false) && $this->url() !== '' && $this->key() !== '';
    }

    public function decide(string $system, string $contact, string $channel, string $text = '', ?int $organizerId = null, string $kind = 'auto'): UneAnickaDecisionDTO
    {
        if (! $this->isEnabled() || trim($contact) === '') {
            return UneAnickaDecisionDTO::allowedByDefault('router disabled');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders(['x-file-key' => $this->key()])
                ->connectTimeout(1)
                ->timeout(max(1, (int) $this->config->get('services.une_anicka.timeout_seconds', 4)))
                ->post($this->url().'/v1/une-anicka/decider', [
                    'systeme' => $system,
                    'contact' => $contact,
                    'canal' => $channel,
                    'genre' => $kind,
                    'texte' => mb_substr($text, 0, 1000),
                    'organisateur' => $organizerId,
                ]);
        } catch (Throwable $exception) {
            $this->logger->warning('Une Anicka router unreachable, reply allowed', ['system' => $system, 'error' => $exception->getMessage()]);

            return UneAnickaDecisionDTO::allowedByDefault('router unreachable');
        }

        if (! $response->successful() || ! is_array($response->json())) {
            $this->logger->warning('Une Anicka router answered badly, reply allowed', ['system' => $system, 'status' => $response->status()]);

            return UneAnickaDecisionDTO::allowedByDefault('router error');
        }

        $decision = new UneAnickaDecisionDTO(
            allowed: (bool) $response->json('ok', true),
            rule: (string) $response->json('regle', ''),
            reason: (string) $response->json('raison', ''),
        );

        if (! $decision->allowed) {
            $this->logger->info('Une Anicka router held an automatic reply', [
                'system' => $system,
                'channel' => $channel,
                'rule' => $decision->rule,
                'reason' => $decision->reason,
            ]);
        }

        return $decision;
    }

    private function url(): string
    {
        return rtrim((string) $this->config->get('services.une_anicka.url', ''), '/');
    }

    private function key(): string
    {
        return (string) $this->config->get('services.une_anicka.key', '');
    }
}
