<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\Models;

use Digit\Bracelets\Domain\Enums\BraceletStatus;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `digit_bracelets`, entirely owned by this module.
 * Relations point to native Hi.Events models (Event, Attendee) but are
 * defined unilaterally here - no native model is modified to know about
 * bracelets.
 *
 * @property int $id
 * @property string $code
 * @property int $event_id
 * @property int $account_id
 * @property int|null $attendee_id
 * @property string|null $batch_label
 * @property BraceletStatus $status
 * @property \Illuminate\Support\Carbon|null $printed_at
 * @property \Illuminate\Support\Carbon|null $assigned_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $compromised_at
 */
class DigitBracelet extends Model
{
    protected $table = 'digit_bracelets';

    protected $fillable = [
        'code',
        'event_id',
        'account_id',
        'attendee_id',
        'batch_label',
        'status',
        'printed_at',
        'assigned_at',
        'revoked_at',
        'compromised_at',
    ];

    protected $casts = [
        'status' => BraceletStatus::class,
        'printed_at' => 'datetime',
        'assigned_at' => 'datetime',
        'revoked_at' => 'datetime',
        'compromised_at' => 'datetime',
    ];

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class, 'attendee_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
}
