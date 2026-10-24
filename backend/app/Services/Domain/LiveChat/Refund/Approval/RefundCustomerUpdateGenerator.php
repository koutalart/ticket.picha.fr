<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund\Approval;

use HiEvents\Exceptions\EventAssistantFailedException;
use HiEvents\Exceptions\EventAssistantUnavailableException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Services\Domain\Sms\SmsAssistantReplyGenerator;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use Illuminate\Config\Repository;
use Psr\Log\LoggerInterface;

/**
 * The news a customer gets while the organizer decides on their refund: human, short, worded differently each time.
 * Written by the model, with fixed variants when it is not available.
 */
class RefundCustomerUpdateGenerator
{
    public const MAX_LENGTH = 300;

    public const FALLBACK_KEYS = [
        'Your refund request :reference is still with the organizer. I am following it closely and will write to you as soon as it is decided.',
        'Quick update on your request :reference: the organizer has not decided yet. I am keeping an eye on it for you.',
        'I have not forgotten your refund request :reference. The organizer is looking into it and I will let you know as soon as I hear back.',
        'Still no decision on :reference for now, I am following up with the organizer and will get back to you very soon.',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
Tu écris, au nom de l'organisateur nommé dans les données, un court message de nouvelles à un client qui a demandé le remboursement de ses billets. La demande attend encore la décision de l'organisateur.

Règles :
- 1 à 2 phrases, 300 caractères au plus, ton humain et chaleureux, comme un vrai SMS ;
- rappelle le numéro de la demande ;
- dis que la demande est toujours en cours chez l'organisateur et que tu le tiens au courant ;
- ne promets rien : ni remboursement, ni délai précis, ni autre solution ;
- formulation différente des messages déjà envoyés (indiqués dans les données) ;
- n'écris jamais « je vois avec l'équipe » ;
- n'utilise jamais les caractères « • », « · », « — » ou « – » ;
- ne parle pas d'intelligence artificielle, ne signe pas ;
- vouvoiement, sauf si les données indiquent le tutoiement.
PROMPT;

    public function __construct(
        private readonly ClaudeStructuredOutputClient $claude,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  array<int, string>  $previous  Messages already sent to the customer for this request
     */
    public function generate(string $organizerName, string $reference, int $updateNumber, string $locale, array $previous = []): string
    {
        if ($this->claude->isConfigured()) {
            try {
                $answer = $this->claude->askWithUsage(
                    system: self::SYSTEM_PROMPT,
                    content: [['type' => 'text', 'text' => implode("\n\n", [
                        'Organisateur : '.$organizerName,
                        'Numéro de la demande : '.$reference,
                        'Message de nouvelles numéro '.$updateNumber,
                        CustomerLocale::isTutoiement($locale) ? 'Tutoiement : oui' : 'Tutoiement : non',
                        "Messages déjà envoyés :\n".($previous === [] ? 'aucun' : implode("\n", $previous)),
                    ])]],
                    schema: [
                        'type' => 'object',
                        'properties' => ['message' => ['type' => 'string']],
                        'required' => ['message'],
                        'additionalProperties' => false,
                    ],
                    model: (string) $this->config->get('services.anthropic.organizer_assistant_model'),
                    maxTokens: 600,
                );

                $message = SmsAssistantReplyGenerator::cleanText((string) ($answer->data['message'] ?? ''));
                if ($message !== ''
                    && mb_strlen($message) <= self::MAX_LENGTH
                    && preg_match('/(?<!\pL)[ée]quipes?(?!\pL)|https?:\/\//iu', $message) !== 1
                    && ! in_array($message, $previous, true)) {
                    return $message;
                }
            } catch (EventAssistantUnavailableException|EventAssistantFailedException $exception) {
                $this->logger->info('Refund update: model unavailable, fixed variant used', ['error' => $exception->getMessage()]);
            }
        }

        return self::fallback($reference, $updateNumber, $locale);
    }

    public static function fallback(string $reference, int $updateNumber, string $locale): string
    {
        $key = self::FALLBACK_KEYS[max(0, $updateNumber - 1) % count(self::FALLBACK_KEYS)];

        return __($key, ['reference' => $reference], $locale);
    }
}
