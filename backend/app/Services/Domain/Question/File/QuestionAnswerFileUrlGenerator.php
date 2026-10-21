<?php

namespace HiEvents\Services\Domain\Question\File;

use Carbon\CarbonInterface;
use HiEvents\Helper\Url;
use Illuminate\Contracts\Routing\UrlGenerator;

class QuestionAnswerFileUrlGenerator
{
    public const DOWNLOAD_ROUTE_NAME = 'question-files.download';

    public function __construct(
        private readonly UrlGenerator $urlGenerator,
    ) {}

    public function getDownloadUrl(int $fileId, CarbonInterface $expiresAt): string
    {
        $relativeUrl = $this->urlGenerator->temporarySignedRoute(
            name: self::DOWNLOAD_ROUTE_NAME,
            expiration: $expiresAt,
            parameters: ['file_id' => $fileId],
            absolute: false,
        );

        return Url::getApiUrl($relativeUrl);
    }

    public function getDownloadUrlFromAnswer(mixed $answer, CarbonInterface $expiresAt): ?string
    {
        if (! is_array($answer) || empty($answer['file_id'])) {
            return null;
        }

        return $this->getDownloadUrl((int) $answer['file_id'], $expiresAt);
    }
}
