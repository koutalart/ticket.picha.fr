<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\Exceptions\SmsAssistantFailedException;
use HiEvents\Exceptions\SmsAssistantLimitReachedException;
use HiEvents\Exceptions\SmsAssistantUnavailableException;
use HiEvents\Services\Application\Handlers\Sms\DTO\PreviewSmsAssistantDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantResultDTO;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;

class PreviewSmsAssistantHandler
{
    public function __construct(
        private readonly SmsAssistantService $assistantService,
        private readonly HtmlPurifierService $purifier,
    ) {}

    /**
     * @throws SmsAssistantUnavailableException
     * @throws SmsAssistantLimitReachedException
     * @throws SmsAssistantFailedException
     */
    public function handle(PreviewSmsAssistantDTO $dto): SmsAssistantResultDTO
    {
        return $this->assistantService->preview(
            organizerId: $dto->organizerId,
            customerMessage: $this->plainText($dto->customerMessage) ?? '',
            instructions: $this->plainText($dto->instructions),
        );
    }

    private function plainText(?string $text): ?string
    {
        $purified = (string) $this->purifier->purify($text);
        $plain = trim(strip_tags(html_entity_decode($purified, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $plain !== '' ? $plain : null;
    }
}
