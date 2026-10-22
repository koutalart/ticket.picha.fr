<?php

namespace HiEvents\Models;

class ContactImport extends BaseModel
{
    protected $table = 'contact_imports';

    protected function getCastMap(): array
    {
        return [
            'column_mapping' => 'array',
            'rejected_rows' => 'array',
        ];
    }
}
