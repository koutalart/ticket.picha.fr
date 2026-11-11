<?php

declare(strict_types=1);

namespace HiEvents\Models;

class AssistantAction extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'params' => 'array',
            'result' => 'array',
        ];
    }
}
