<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SmsMessageDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<SmsMessageDomainObject>
 */
interface SmsMessageRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, SmsMessageDomainObject>
     */
    public function findLatestInbound(int $conversationId, int $limit): Collection;

    /**
     * The most recent messages of a conversation, oldest first.
     *
     * @return Collection<int, SmsMessageDomainObject>
     */
    public function findLatestForConversation(int $conversationId, int $limit): Collection;
}
