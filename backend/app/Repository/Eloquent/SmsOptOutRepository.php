<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\Models\SmsOptOut;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsOptOutSearchDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<SmsOptOutDomainObject>
 */
class SmsOptOutRepository extends BaseRepository implements SmsOptOutRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsOptOut::class;
    }

    public function getDomainObject(): string
    {
        return SmsOptOutDomainObject::class;
    }

    public function searchForOrganizer(int $organizerId, SmsOptOutSearchDTO $search): LengthAwarePaginator
    {
        $query = SmsOptOut::query()
            ->select('sms_opt_outs.*', 'sms_conversations.contact_name')
            ->leftJoin('sms_conversations', function ($join) {
                $join->on('sms_conversations.organizer_id', '=', 'sms_opt_outs.organizer_id')
                    ->on('sms_conversations.phone', '=', 'sms_opt_outs.phone');
            })
            ->where('sms_opt_outs.organizer_id', $organizerId);

        $term = trim((string) $search->query);
        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $digits = preg_replace('/\D+/', '', $term) ?? '';

            $query->where(function ($inner) use ($like, $digits) {
                $inner->where('sms_conversations.contact_name', 'ilike', $like)
                    ->orWhere('sms_opt_outs.phone', 'ilike', $like);

                if (strlen($digits) >= 4) {
                    $inner->orWhere('sms_opt_outs.phone', 'like', '%'.$digits.'%');
                }
            });
        }

        $results = $query
            ->orderByDesc('sms_opt_outs.created_at')
            ->orderByDesc('sms_opt_outs.id')
            ->paginate(perPage: $search->perPage, page: $search->page);

        return $this->handleResults($results);
    }

    public function findWithFrenchOverseasPhones(): Collection
    {
        return $this->handleResults(SmsOptOut::query()
            ->whereRaw("phone ~ '^\\+33(639|692|693|262|263|269)[0-9]{6}$'")
            ->orderBy('id')
            ->get());
    }
}
