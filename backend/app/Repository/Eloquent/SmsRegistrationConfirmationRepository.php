<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsRegistrationConfirmationDomainObject;
use HiEvents\DomainObjects\Status\SmsRegistrationConfirmationStatus;
use HiEvents\Models\SmsRegistrationConfirmation;
use HiEvents\Repository\Interfaces\SmsRegistrationConfirmationRepositoryInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<SmsRegistrationConfirmationDomainObject>
 */
class SmsRegistrationConfirmationRepository extends BaseRepository implements SmsRegistrationConfirmationRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsRegistrationConfirmation::class;
    }

    public function getDomainObject(): string
    {
        return SmsRegistrationConfirmationDomainObject::class;
    }

    public function findSentToAlreadyConfirmedPhoneSince(array $organizerIds, string $since, int $limit): Collection
    {
        $sent = SmsRegistrationConfirmationStatus::SENT->name;

        $confirmations = SmsRegistrationConfirmation::query()
            ->whereIn('organizer_id', $organizerIds)
            ->where('status', $sent)
            ->where('created_at', '>=', $since)
            ->whereNull('phone_released_at')
            ->whereExists(fn (Builder $earlier) => $earlier
                ->selectRaw('1')
                ->from('sms_registration_confirmations as earlier')
                ->whereColumn('earlier.event_id', 'sms_registration_confirmations.event_id')
                ->whereColumn('earlier.phone', 'sms_registration_confirmations.phone')
                ->whereColumn('earlier.id', '<', 'sms_registration_confirmations.id')
                ->where('earlier.status', $sent)
                ->where(fn (Builder $notReleased) => $notReleased
                    ->whereNull('earlier.phone_released_at')
                    ->orWhereColumn('earlier.phone_released_at', '>', 'sms_registration_confirmations.created_at')))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $this->handleResults($confirmations);
    }
}
