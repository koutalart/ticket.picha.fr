<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoxOfficeSaleItem extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'unit_amount' => 'float',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(BoxOfficeSale::class, 'box_office_sale_id');
    }
}
