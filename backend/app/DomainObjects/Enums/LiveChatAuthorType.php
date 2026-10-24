<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum LiveChatAuthorType: string
{
    use BaseEnum;

    case VISITOR = 'VISITOR';
    case AGENT = 'AGENT';
}
