<?php

declare(strict_types=1);

namespace HiEvents\Resources\Event;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Application\Handlers\Event\DailyReport\DTO\EventDailyReportViewDTO;

/**
 * @mixin EventDailyReportViewDTO
 */
class EventDailyReportResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'event_id' => $this->eventId,
            'enabled' => $this->enabled,
            'send_time' => $this->sendTime,
            'timezone' => $this->timezone,
            'copy_supervisors' => $this->copySupervisors,
            'last_sent_on' => $this->lastSentOn,
            'team_contacts_count' => $this->teamContactsCount,
            'preview' => $this->preview,
        ];
    }
}
