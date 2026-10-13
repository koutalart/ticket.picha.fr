<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\QrLinks\Public;

use HiEvents\DomainObjects\Enums\QrLinkVisitType;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\QrLink\RecordQrLinkVisitRequest;
use HiEvents\Services\Application\Handlers\QrLink\RecordQrLinkVisitHandler;
use HiEvents\Services\Domain\QrLink\DTO\QrLinkVisitContextDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class RecordQrLinkVisitPublicAction extends BaseAction
{
    public function __construct(
        private readonly RecordQrLinkVisitHandler $handler,
    ) {}

    public function __invoke(RecordQrLinkVisitRequest $request, string $shortId): JsonResponse|Response
    {
        try {
            $result = $this->handler->handle($shortId, new QrLinkVisitContextDTO(
                type: QrLinkVisitType::from($request->validated('type')),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
                target: $request->validated('target'),
                referrer: $request->validated('referrer'),
                countryCode: $request->header('CF-IPCountry'),
            ));
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->jsonResponse($result->toArray(), wrapInData: true);
    }
}
