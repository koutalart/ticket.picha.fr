<?php

declare(strict_types=1);

namespace Digit\Bracelets\Security\Models;

use HiEvents\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per event. `secret` is cast `encrypted` - Laravel transparently
 * encrypts/decrypts it using APP_KEY, so it is never stored or read in
 * plaintext. Never expose this value outside EventSecurityKeyService.
 */
class DigitEventSecurityKey extends Model
{
    protected $table = 'digit_event_security_keys';

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'secret',
        'created_at',
        'rotated_at',
        'notes',
    ];

    protected $casts = [
        'secret' => 'encrypted',
        'created_at' => 'datetime',
        'rotated_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
}
