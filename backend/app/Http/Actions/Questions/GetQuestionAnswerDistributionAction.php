<?php

namespace HiEvents\Http\Actions\Questions;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Question\GetQuestionAnswerDistributionHandler;
use Illuminate\Http\JsonResponse;

class GetQuestionAnswerDistributionAction extends BaseAction
{
    public function __construct(
        private readonly GetQuestionAnswerDistributionHandler $handler,
    ) {}

    public function __invoke(int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $distribution = $this->handler->handle($eventId);

        return $this->jsonResponse($distribution->map->toArray()->values()->all(), wrapInData: true);
    }
}
