<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Contacts\Public;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Contact\RecordContactLinkVisitRequest;
use HiEvents\Services\Application\Handlers\Contact\RecordContactLinkVisitHandler;
use HiEvents\Services\Domain\Contact\DTO\ContactLinkVisitContextDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class RecordContactLinkVisitPublicAction extends BaseAction
{
    public function __construct(
        private readonly RecordContactLinkVisitHandler $handler,
    ) {}

    public function __invoke(RecordContactLinkVisitRequest $request, string $code): JsonResponse|Response
    {
        try {
            $recorded = $this->handler->handle($code, new ContactLinkVisitContextDTO(
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
                referrer: $request->validated('referrer'),
                countryCode: $request->header('CF-IPCountry'),
            ));
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->jsonResponse(['recorded' => $recorded], wrapInData: true);
    }
}
