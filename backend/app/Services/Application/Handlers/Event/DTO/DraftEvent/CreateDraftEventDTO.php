<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent;

use HiEvents\DataTransferObjects\BaseDataObject;

class CreateDraftEventDTO extends BaseDataObject
{
    /**
     * @param  DraftTicketDTO[]  $tickets
     * @param  DraftQuestionDTO[]  $questions
     */
    public function __construct(
        public readonly int $organizer_id,
        public readonly int $account_id,
        public readonly int $user_id,
        public readonly string $title,
        public readonly string $start_date,
        public readonly string $timezone,
        public readonly array $tickets,
        public readonly array $questions = [],
        public readonly ?string $end_date = null,
        public readonly ?string $description = null,
        public readonly ?string $venue_name = null,
    ) {}
}
