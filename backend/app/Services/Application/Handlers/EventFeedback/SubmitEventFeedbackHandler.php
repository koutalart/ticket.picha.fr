<?php

namespace HiEvents\Services\Application\Handlers\EventFeedback;

use HiEvents\DomainObjects\EventFeedbackDomainObject;
use HiEvents\Exceptions\EventFeedbackNotOpenException;
use HiEvents\Repository\Interfaces\EventFeedbackRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\EventFeedback\DTO\SubmitEventFeedbackDTO;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class SubmitEventFeedbackHandler
{
    public function __construct(
        private readonly EventFeedbackRepositoryInterface $feedbackRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly HtmlPurifierService $purifier,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @throws EventFeedbackNotOpenException
     */
    public function handle(SubmitEventFeedbackDTO $dto): EventFeedbackDomainObject
    {
        if (! $this->isOpen($dto->event_id) || $this->eventRepository->findFirstWhere(['id' => $dto->event_id]) === null) {
            throw new EventFeedbackNotOpenException(__('Feedback is not open for this event.'));
        }

        if ($dto->rating < 1 || $dto->rating > 5) {
            throw new EventFeedbackNotOpenException(__('The rating must be between 1 and 5.'));
        }

        $attributes = [
            'rating' => $dto->rating,
            'title' => $this->clean($dto->title, 80),
            'first_name' => $this->clean($dto->first_name, 40),
            'artists' => $this->clean($dto->artists, 80),
            'welcome' => $this->clean($dto->welcome, 80),
            'come_back' => $this->clean($dto->come_back, 80),
            'message' => $this->clean($dto->message, 600),
            'publish_consent' => $dto->publish_consent,
            'ip_hash' => $dto->ip !== null ? hash('sha256', $dto->ip.'|'.$this->config->get('app.key')) : null,
        ];

        $code = $this->clean($dto->contact_code, 64);
        if ($code !== null) {
            $existing = $this->feedbackRepository->findFirstWhere([
                'event_id' => $dto->event_id,
                'contact_code' => $code,
            ]);

            if ($existing !== null) {
                return $this->feedbackRepository->updateFromArray($existing->getId(), $attributes);
            }
        }

        return $this->feedbackRepository->create(array_merge($attributes, [
            'event_id' => $dto->event_id,
            'contact_code' => $code,
        ]));
    }

    private function isOpen(int $eventId): bool
    {
        $ids = array_filter(array_map('trim', explode(',', (string) $this->config->get('services.feedback.event_ids', ''))));

        return in_array((string) $eventId, $ids, true);
    }

    private function clean(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags((string) $this->purifier->purify($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, $max);

        return $text === '' ? null : $text;
    }
}
