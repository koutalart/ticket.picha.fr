<?php

declare(strict_types=1);

namespace HiEvents\Models;

class LiveChatQuestion extends BaseModel
{
    protected function getCastMap(): array
    {
        return ['sent_to_supervision' => 'boolean'];
    }
}
