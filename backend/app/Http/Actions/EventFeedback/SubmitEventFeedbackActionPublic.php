<?php

namespace HiEvents\Http\Actions\EventFeedback;

use HiEvents\Exceptions\EventFeedbackNotOpenException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\EventFeedback\SubmitEventFeedbackRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\EventFeedback\DTO\SubmitEventFeedbackDTO;
use HiEvents\Services\Application\Handlers\EventFeedback\SubmitEventFeedbackHandler;
use Illuminate\Http\JsonResponse;

class SubmitEventFeedbackActionPublic extends BaseAction
{
    public function __construct(
        private readonly SubmitEventFeedbackHandler $handler,
    ) {}

    public function __invoke(SubmitEventFeedbackRequest $request, int $eventId): JsonResponse
    {
        try {
            $this->handler->handle(new SubmitEventFeedbackDTO(
                event_id: $eventId,
                rating: (int) $request->validated('note'),
                contact_code: $request->validated('c') ?: null,
                title: $request->validated('titre'),
                first_name: $request->validated('prenom'),
                artists: $request->validated('artistes'),
                welcome: $request->validated('accueil'),
                come_back: $request->validated('revenir'),
                message: $request->validated('mot'),
                publish_consent: $request->validated('publier') === 'oui',
                ip: $this->getClientIp($request),
            ));
        } catch (EventFeedbackNotOpenException $exception) {
            return $this->errorResponse(
                message: $exception->getMessage(),
                statusCode: ResponseCodes::HTTP_NOT_FOUND,
            );
        }

        return $this->jsonResponse(['ok' => true], ResponseCodes::HTTP_CREATED);
    }
}
