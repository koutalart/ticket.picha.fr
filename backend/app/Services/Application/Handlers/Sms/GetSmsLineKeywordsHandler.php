<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\Services\Domain\Sms\DTO\SmsLineKeywordsViewDTO;
use HiEvents\Services\Domain\Sms\SmsLineKeywordService;

class GetSmsLineKeywordsHandler
{
    public function __construct(
        private readonly SmsLineKeywordService $keywordService,
    ) {}

    public function handle(int $organizerId): SmsLineKeywordsViewDTO
    {
        return $this->keywordService->view($organizerId);
    }
}
