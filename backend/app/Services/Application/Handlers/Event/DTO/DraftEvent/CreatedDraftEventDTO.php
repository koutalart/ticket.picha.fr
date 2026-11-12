<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\EventDomainObject;

class CreatedDraftEventDTO extends BaseDataObject
{
    /**
     * @param  int[]  $ticket_ids
     * @param  int[]  $question_ids
     */
    public function __construct(
        public readonly EventDomainObject $event,
        public readonly array $ticket_ids,
        public readonly array $question_ids,
    ) {}
}
