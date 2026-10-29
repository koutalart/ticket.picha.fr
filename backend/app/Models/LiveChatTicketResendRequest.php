<?php

declare(strict_types=1);

namespace HiEvents\Models;

class LiveChatTicketResendRequest extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'order_ids' => 'array',
        ];
    }
}
