<?php

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Services\Domain\Event\DTO\DuplicateEventDataDTO;
use HiEvents\Services\Domain\Event\DuplicateEventService;
use HiEvents\Services\Domain\Sms\Reminder\SmsDefaultRemindersService;
use Throwable;

class DuplicateEventHandler
{
    public function __construct(
        private readonly DuplicateEventService      $duplicateEventService,
        private readonly SmsDefaultRemindersService $defaultRemindersService,
    )
    {
    }

    /**
     * @throws Throwable
     */
    public function handle(DuplicateEventDataDTO $data): EventDomainObject
    {
        $event = $this->duplicateEventService->duplicateEvent(
            eventId: $data->eventId,
            accountId: $data->accountId,
            title: $data->title,
            startDate: $data->startDate,
            duplicateProducts: $data->duplicateProducts,
            duplicateQuestions: $data->duplicateQuestions,
            duplicateSettings: $data->duplicateSettings,
            duplicatePromoCodes: $data->duplicatePromoCodes,
            duplicateCapacityAssignments: $data->duplicateCapacityAssignments,
            duplicateCheckInLists: $data->duplicateCheckInLists,
            duplicateEventCoverImage: $data->duplicateEventCoverImage,
            duplicateTicketLogo: $data->duplicateTicketLogo,
            duplicateWebhooks: $data->duplicateWebhooks,
            duplicateAffiliates: $data->duplicateAffiliates,
            description: $data->description,
            endDate: $data->endDate,
        );

        $this->defaultRemindersService->createForEventSafely($event);

        return $event;
    }
}
