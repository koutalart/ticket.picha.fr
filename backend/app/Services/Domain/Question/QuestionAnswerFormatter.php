<?php

namespace HiEvents\Services\Domain\Question;

use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\Services\Domain\Question\File\QuestionAnswerFileUrlGenerator;

class QuestionAnswerFormatter
{
    public const EXPORT_FILE_URL_TTL_DAYS = 7;

    public function __construct(
        private readonly QuestionAnswerFileUrlGenerator $fileUrlGenerator,
    ) {}

    public function getAnswerAsText(string|array $answer, QuestionTypeEnum $questionType, bool $includeFileUrl = true): string
    {
        if ($questionType === QuestionTypeEnum::FILE) {
            return $this->getFileAnswerAsText($answer, $includeFileUrl);
        }

        if ($questionType === QuestionTypeEnum::ADDRESS) {
            $addressLines = [
                $answer['address_line_1'] ?? null,
                $answer['address_line_2'] ?? null,
                $answer['city'] ?? null,
                $answer['state_or_region'] ?? null,
                $answer['zip_or_postal_code'] ?? null,
                $answer['country'] ?? null,
            ];

            return implode(', ', array_filter($addressLines, static function ($line) {
                return ! empty($line);
            }));
        }

        if (is_array($answer)) {
            return implode(', ', $answer);
        }

        return $answer;
    }

    private function getFileAnswerAsText(string|array $answer, bool $includeFileUrl): string
    {
        if (! is_array($answer)) {
            return $answer;
        }

        $name = (string) ($answer['name'] ?? '');

        if (! $includeFileUrl) {
            return $name;
        }

        $url = $this->fileUrlGenerator->getDownloadUrlFromAnswer(
            $answer,
            now()->addDays(self::EXPORT_FILE_URL_TTL_DAYS),
        );

        return $url ? trim($name.' '.$url) : $name;
    }
}
