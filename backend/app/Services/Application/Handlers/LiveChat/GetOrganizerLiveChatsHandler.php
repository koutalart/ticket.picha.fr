<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\LiveChat;

use HiEvents\DomainObjects\Generated\LiveChatSessionDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\LiveChatMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatSessionRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class GetOrganizerLiveChatsHandler
{
    private const TRANSCRIPT_LENGTH = 200;

    public function __construct(
        private readonly LiveChatSessionRepositoryInterface $sessionRepository,
        private readonly LiveChatMessageRepositoryInterface $messageRepository,
    ) {}

    public function list(int $organizerId, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->sessionRepository->paginateForOrganizer($organizerId, $page, $perPage);
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function get(int $organizerId, int $sessionId): LiveChatSessionDomainObject
    {
        /** @var LiveChatSessionDomainObject|null $session */
        $session = $this->sessionRepository->findFirstWhere([
            LiveChatSessionDomainObjectAbstract::ID => $sessionId,
            LiveChatSessionDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($session === null) {
            throw new ResourceNotFoundException(__('Chat not found'));
        }

        return $session->setLiveChatMessages($this->messageRepository->findLatestForSession($sessionId, self::TRANSCRIPT_LENGTH));
    }
}
