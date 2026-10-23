<?php

declare(strict_types=1);

namespace HiEvents\Models;

class SmsUnattributedMessage extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'opt_out_applied' => 'boolean',
        ];
    }
}
