<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Email;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Email\SearchEmailRecipientsHandler;
use HiEvents\Services\Domain\Email\Inbox\DTO\EmailRecipientDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchEmailRecipientsAction extends BaseAction
{
    public function __construct(
        private readonly SearchEmailRecipientsHandler $handler,
    ) {}

    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $recipients = $this->handler->handle($organizerId, mb_substr((string) $request->query('query', ''), 0, 100));

        return $this->jsonResponse(
            $recipients->map(fn (EmailRecipientDTO $recipient) => [
                'email' => $recipient->email,
                'name' => $recipient->name,
                'thread_id' => $recipient->threadId,
                'unsubscribed' => $recipient->unsubscribed,
            ])->values()->all(),
            wrapInData: true,
        );
    }
}
