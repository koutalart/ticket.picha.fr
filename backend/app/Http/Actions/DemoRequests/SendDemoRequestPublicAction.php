<?php

namespace HiEvents\Http\Actions\DemoRequests;

use HiEvents\DomainObjects\Enums\DemoRequestEventType;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\DemoRequest\DTO\SendDemoRequestDTO;
use HiEvents\Services\Application\Handlers\DemoRequest\SendDemoRequestHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SendDemoRequestPublicAction extends BaseAction
{
    public const ATTENDEE_COUNT_RANGES = ['<50', '50-150', '150-500', '500-1000', '>1000'];

    public function __construct(
        private readonly SendDemoRequestHandler $handler,
    )
    {
    }

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|max:255',
            'organization' => 'required|string|max:255',
            'event_type' => ['required', Rule::in(DemoRequestEventType::valuesArray())],
            'event_date' => 'nullable|date_format:Y-m',
            'attendee_count' => ['required', Rule::in(self::ATTENDEE_COUNT_RANGES)],
        ]);

        if ($request->filled('website')) {
            return $this->jsonResponse([
                'message' => __('Your demo request has been sent'),
            ]);
        }

        $this->handler->handle(SendDemoRequestDTO::from([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'organization' => $data['organization'],
            'event_type' => DemoRequestEventType::from($data['event_type']),
            'event_date' => $data['event_date'] ?? null,
            'attendee_count' => $data['attendee_count'],
        ]));

        return $this->jsonResponse([
            'message' => __('Your demo request has been sent'),
        ]);
    }
}
