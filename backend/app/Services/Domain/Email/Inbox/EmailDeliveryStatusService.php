<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox;

use Carbon\Carbon;
use HiEvents\DomainObjects\EmailMessageDomainObject;
use HiEvents\DomainObjects\Generated\EmailMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EmailMessageStatus;
use HiEvents\Repository\Interfaces\EmailMessageRepositoryInterface;
use HiEvents\Services\Infrastructure\EmailProvider\DTO\EmailProviderEventDTO;
use Throwable;

class EmailDeliveryStatusService
{
    public function __construct(
        private readonly EmailMessageRepositoryInterface $messageRepository,
        private readonly EmailThreadService $threadService,
    ) {}

    /**
     * Applies a delivery event to the logged e-mail. A status never goes back (an "opened" e-mail stays opened
     * when a late "delivered" arrives) except for bounces, complaints and failures which always win.
     */
    public function apply(EmailProviderEventDTO $event): ?EmailMessageDomainObject
    {
        if ($event->status === null) {
            return null;
        }

        /** @var EmailMessageDomainObject|null $message */
        $message = $this->messageRepository->findFirstWhere([
            EmailMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $event->providerEmailId,
        ]);

        if ($message === null) {
            return null;
        }

        $current = EmailMessageStatus::tryFrom($message->getStatus());
        $occurredAt = $this->toDateTime($event->occurredAt);
        $attributes = [];

        if ($event->status === EmailMessageStatus::DELIVERED && $message->getDeliveredAt() === null) {
            $attributes[EmailMessageDomainObjectAbstract::DELIVERED_AT] = $occurredAt;
        }

        if ($event->status === EmailMessageStatus::OPENED && $message->getOpenedAt() === null) {
            $attributes[EmailMessageDomainObjectAbstract::OPENED_AT] = $occurredAt;
        }

        $promote = $current === null
            || $event->status->isFailure()
            || (! $current->isFailure() && $event->status->rank() > $current->rank());

        if ($promote && $current !== $event->status) {
            $attributes[EmailMessageDomainObjectAbstract::STATUS] = $event->status->name;
            $attributes[EmailMessageDomainObjectAbstract::STATUS_UPDATED_AT] = $occurredAt;

            if ($event->status->isFailure() && $event->errorMessage !== null) {
                $attributes[EmailMessageDomainObjectAbstract::ERROR_MESSAGE] = $event->errorMessage;
            }
        }

        if ($attributes === []) {
            return $message;
        }

        $message = $this->messageRepository->updateFromArray($message->getId(), $attributes);

        if (isset($attributes[EmailMessageDomainObjectAbstract::STATUS])) {
            $this->threadService->refreshLastStatus($message, $message->getStatus());
        }

        return $message;
    }

    private function toDateTime(?string $value): string
    {
        try {
            return $value !== null ? Carbon::parse($value)->utc()->toDateTimeString() : now()->toDateTimeString();
        } catch (Throwable) {
            return now()->toDateTimeString();
        }
    }
}
