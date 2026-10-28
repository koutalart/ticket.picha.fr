<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum LiveChatQuestionStatus: string
{
    use BaseEnum;

    case AWAITING_CONTACT = 'AWAITING_CONTACT';
    case AWAITING = 'AWAITING';
    case ANSWERED = 'ANSWERED';
    case RELAYED = 'RELAYED';
}
