<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Domain\Sms\SmsLineKeywordService;

class DeleteSmsLineKeywordHandler
{
    public function __construct(
        private readonly SmsLineKeywordService $keywordService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $keywordId): void
    {
        $this->keywordService->delete($organizerId, $keywordId);
    }
}
