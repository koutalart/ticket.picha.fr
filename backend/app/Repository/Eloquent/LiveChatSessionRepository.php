<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Generated\LiveChatSessionDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\Models\LiveChatSession;
use HiEvents\Repository\Interfaces\LiveChatSessionRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<LiveChatSessionDomainObject>
 */
class LiveChatSessionRepository extends BaseRepository implements LiveChatSessionRepositoryInterface
{
    protected function getModel(): string
    {
        return LiveChatSession::class;
    }

    public function getDomainObject(): string
    {
        return LiveChatSessionDomainObject::class;
    }

    public function paginateForOrganizer(int $organizerId, int $page, int $perPage): LengthAwarePaginator
    {
        $results = LiveChatSession::query()
            ->where(LiveChatSessionDomainObjectAbstract::ORGANIZER_ID, $organizerId)
            ->where(LiveChatSessionDomainObjectAbstract::VISITOR_MESSAGE_COUNT, '>', 0)
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->orderByDesc(LiveChatSessionDomainObjectAbstract::ID)
            ->paginate(perPage: $perPage, page: $page);

        return $this->handleResults($results);
    }

    public function deleteWithoutContactBefore(string $before): int
    {
        return LiveChatSession::query()
            ->whereNull(LiveChatSessionDomainObjectAbstract::VISITOR_PHONE)
            ->whereNull(LiveChatSessionDomainObjectAbstract::VISITOR_EMAIL)
            ->whereRaw('COALESCE(last_message_at, created_at) < ?', [$before])
            ->delete();
    }
}
