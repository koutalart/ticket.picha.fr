<?php

namespace HiEvents\Http\Actions\CaseStudies;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\CaseStudy\GetCaseStudyStatsHandler;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class GetCaseStudyStatsAction extends BaseAction
{
    private const MAX_EVENTS = 20;

    public function __construct(
        private readonly GetCaseStudyStatsHandler $handler,
        private readonly Repository $config,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request): JsonResponse|Response
    {
        $token = (string) $this->config->get('app.case_study_stats_token');

        if ($token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return $this->notFoundResponse();
        }

        $data = $this->validate($request, [
            'event_ids' => ['required', 'string', 'regex:/^\d+(,\d+)*$/'],
        ]);

        $eventIds = array_slice(array_unique(array_map('intval', explode(',', $data['event_ids']))), 0, self::MAX_EVENTS);

        return $this->jsonResponse($this->handler->handle($eventIds), wrapInData: true);
    }
}
