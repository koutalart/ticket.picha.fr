<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Generated\LiveChatMessageDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\Models\LiveChatMessage;
use HiEvents\Repository\Interfaces\LiveChatMessageRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<LiveChatMessageDomainObject>
 */
class LiveChatMessageRepository extends BaseRepository implements LiveChatMessageRepositoryInterface
{
    protected function getModel(): string
    {
        return LiveChatMessage::class;
    }

    public function getDomainObject(): string
    {
        return LiveChatMessageDomainObject::class;
    }

    public function findLatestForSession(int $sessionId, int $limit): Collection
    {
        $messages = LiveChatMessage::query()
            ->where(LiveChatMessageDomainObjectAbstract::LIVE_CHAT_SESSION_ID, $sessionId)
            ->orderByDesc(LiveChatMessageDomainObjectAbstract::ID)
            ->limit($limit)
            ->get();

        return $this->handleResults($messages)->reverse()->values();
    }
}
