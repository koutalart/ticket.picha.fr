<?php

namespace HiEvents\Services\Application\Handlers\EventPresence;

use HiEvents\DomainObjects\EventPresenceDomainObject;
use HiEvents\Exceptions\EventPresenceRejectedException;
use HiEvents\Exceptions\MissingPhoneCallingCodeException;
use HiEvents\Helper\PhoneNormalizer;
use HiEvents\Repository\Interfaces\EventPresenceRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\EventPresence\DTO\SubmitEventPresenceDTO;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class SubmitEventPresenceHandler
{
    public function __construct(
        private readonly EventPresenceRepositoryInterface $presenceRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly HtmlPurifierService $purifier,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @throws EventPresenceRejectedException
     */
    public function handle(SubmitEventPresenceDTO $dto): EventPresenceDomainObject
    {
        if (! $this->isOpen($dto->event_id) || $this->eventRepository->findFirstWhere(['id' => $dto->event_id]) === null) {
            throw new EventPresenceRejectedException(__('This page is not open for this event.'));
        }

        $firstName = $this->clean($dto->first_name, 40);
        if ($firstName === null) {
            throw new EventPresenceRejectedException(__('The first name is required.'));
        }

        try {
            $phone = PhoneNormalizer::normalize($dto->phone, '262');
        } catch (MissingPhoneCallingCodeException) {
            $phone = null;
        }

        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($phone === null || strlen($digits) < 9 || strlen($digits) > 15) {
            throw new EventPresenceRejectedException(__('The phone number is not valid.'));
        }

        $attributes = [
            'first_name' => $firstName,
            'last_name' => $this->clean($dto->last_name, 60),
            'staff' => $this->clean($dto->staff, 30),
            'ip_hash' => $dto->ip !== null ? hash('sha256', $dto->ip.'|'.$this->config->get('app.key')) : null,
        ];

        $existing = $this->presenceRepository->findFirstWhere([
            'event_id' => $dto->event_id,
            'phone' => $phone,
        ]);

        if ($existing !== null) {
            return $this->presenceRepository->updateFromArray($existing->getId(), $attributes);
        }

        return $this->presenceRepository->create(array_merge($attributes, [
            'event_id' => $dto->event_id,
            'phone' => $phone,
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
