<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\EventAssistant;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use HiEvents\Exceptions\EventAssistantFailedException;
use HiEvents\Exceptions\EventAssistantUnavailableException;
use HiEvents\Services\Domain\EventAssistant\DTO\EventDraftDTO;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use Illuminate\Http\UploadedFile;
use Imagick;
use ImagickException;

class EventAssistantService
{
    private const POSTER_MAX_EDGE = 1568;

    private const DATE_FORMAT = 'Y-m-d\TH:i';

    private const NULLABLE_STRING = ['type' => ['string', 'null']];

    private const NO_DATES_IN_TEXT = 'Never write a date, a day of the week, an hour or a price in the description: they are shown elsewhere on the page and a wrong one would mislead buyers.';

    public function __construct(
        private readonly ClaudeStructuredOutputClient $claude,
    ) {}

    public function isAvailable(): bool
    {
        return $this->claude->isConfigured();
    }

    /**
     * @throws EventAssistantUnavailableException
     * @throws EventAssistantFailedException
     */
    public function readPoster(UploadedFile $poster, string $timezone): EventDraftDTO
    {
        $today = Carbon::now($timezone);

        $answer = $this->claude->ask(
            system: implode("\n", [
                'You read event posters for a French ticketing platform and fill in the event creation form.',
                'Only report what is written on the poster. Leave a field null when the poster does not say it; never guess.',
                sprintf('Today is %s (%s, timezone %s). When the poster gives no year, use the next occurrence of that date.', $today->format('Y-m-d'), $today->locale('fr')->isoFormat('dddd'), $timezone),
                'Dates are local times in the format YYYY-MM-DDTHH:MM. If the poster shows a start hour, use it; otherwise use 00:00 only if a date is given.',
                'If the weekday written on the poster does not match the date, trust the date and do not repeat the weekday anywhere.',
                'price is the cheapest regular entry price in euros as a number, 0 when the poster says free entry, null when it says nothing.',
                'description: two or three short sentences in French presenting the event, based only on the poster. '.self::NO_DATES_IN_TEXT,
            ]),
            content: [
                [
                    'type' => 'image',
                    'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode($this->posterJpeg($poster))],
                ],
                ['type' => 'text', 'text' => 'Fill in the form from this poster.'],
            ],
            schema: [
                'type' => 'object',
                'properties' => [
                    'title' => self::NULLABLE_STRING,
                    'start_date' => self::NULLABLE_STRING,
                    'end_date' => self::NULLABLE_STRING,
                    'venue' => self::NULLABLE_STRING,
                    'description' => self::NULLABLE_STRING,
                    'price' => ['type' => ['number', 'null']],
                ],
                'required' => ['title', 'start_date', 'end_date', 'venue', 'description', 'price'],
                'additionalProperties' => false,
            ],
        );

        $start = $this->localDate($answer['start_date'] ?? null);
        $end = $this->localDate($answer['end_date'] ?? null);
        if ($start === null || ($end !== null && $end <= $start)) {
            $end = null;
        }
        $price = is_numeric($answer['price'] ?? null) && (float) $answer['price'] >= 0 ? round((float) $answer['price'], 2) : null;

        return new EventDraftDTO(
            title: $this->text($answer['title'] ?? null, 150),
            start_date: $start?->format(self::DATE_FORMAT),
            end_date: $end?->format(self::DATE_FORMAT),
            venue: $this->text($answer['venue'] ?? null, 255),
            description: $this->text($answer['description'] ?? null, 2000),
            price: $price,
        );
    }

    /**
     * @throws EventAssistantUnavailableException
     * @throws EventAssistantFailedException
     */
    public function improveDescription(string $description, ?string $title, ?string $venue): string
    {
        $answer = $this->claude->ask(
            system: implode("\n", [
                'You rewrite event descriptions for a French ticketing platform.',
                'Write in French, addressing the reader with "vous", in two or three short paragraphs separated by a blank line, plain text without markdown.',
                'Make it clear, warm and good for search engines (name the event and the place naturally), but keep every fact from the original and invent nothing.',
                self::NO_DATES_IN_TEXT,
            ]),
            content: [[
                'type' => 'text',
                'text' => implode("\n", array_filter([
                    $title ? 'Event title: '.$title : null,
                    $venue ? 'Venue: '.$venue : null,
                    'Description to rewrite:',
                    $description,
                ])),
            ]],
            schema: [
                'type' => 'object',
                'properties' => ['description' => ['type' => 'string']],
                'required' => ['description'],
                'additionalProperties' => false,
            ],
        );

        $text = $this->text($answer['description'] ?? null, 5000);
        if ($text === null) {
            throw new EventAssistantFailedException(__('The assistant could not answer. Please try again or fill in the form yourself.'));
        }

        return $text;
    }

    /**
     * Claude reads images up to ~1568 px on the long edge: send no more than that.
     *
     * @throws EventAssistantFailedException
     */
    private function posterJpeg(UploadedFile $poster): string
    {
        try {
            $image = new Imagick($poster->getRealPath());
            $image->autoOrient();
            if (max($image->getImageWidth(), $image->getImageHeight()) > self::POSTER_MAX_EDGE) {
                $image->thumbnailImage(self::POSTER_MAX_EDGE, self::POSTER_MAX_EDGE, true);
            }
            $image->setImageBackgroundColor('white');
            $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(85);
            $image->stripImage();

            return $image->getImageBlob();
        } catch (ImagickException) {
            throw new EventAssistantFailedException(__('The image could not be read. Use a JPG, PNG, WebP or HEIC photo (8 MB maximum).'));
        }
    }

    private function localDate(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat(self::DATE_FORMAT, trim($value));
        } catch (InvalidFormatException) {
            return null;
        }

        return $date !== null && $date->format(self::DATE_FORMAT) === trim($value) ? $date : null;
    }

    private function text(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(strip_tags($value));

        return $text === '' ? null : mb_substr($text, 0, $maxLength);
    }
}
