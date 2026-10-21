<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Models\SmsMessage;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<SmsMessageDomainObject>
 */
class SmsMessageRepository extends BaseRepository implements SmsMessageRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsMessage::class;
    }

    public function getDomainObject(): string
    {
        return SmsMessageDomainObject::class;
    }

    public function findLatestInbound(int $conversationId, int $limit): Collection
    {
        $messages = SmsMessage::query()
            ->where(SmsMessageDomainObjectAbstract::SMS_CONVERSATION_ID, $conversationId)
            ->where(SmsMessageDomainObjectAbstract::DIRECTION, SmsMessageDirection::INBOUND->name)
            ->orderByDesc(SmsMessageDomainObjectAbstract::ID)
            ->limit($limit)
            ->get();

        return $this->handleResults($messages)->reverse()->values();
    }
}
