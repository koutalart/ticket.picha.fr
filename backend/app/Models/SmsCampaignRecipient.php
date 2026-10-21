<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsCampaignRecipient extends BaseModel
{
    public function sms_campaign(): BelongsTo
    {
        return $this->belongsTo(SmsCampaign::class);
    }

    public function qr_link(): BelongsTo
    {
        return $this->belongsTo(QrLink::class);
    }
}
