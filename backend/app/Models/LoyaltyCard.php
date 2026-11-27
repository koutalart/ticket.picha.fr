<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoyaltyCard extends BaseModel
{
    use SoftDeletes;

    public function loyalty_program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class);
    }
}
