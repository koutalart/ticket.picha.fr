<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Public;

use HiEvents\Exceptions\LiveChatLimitReachedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\LiveChat\PublicLiveChatHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The opaque chat session id travels in a header, never in the URL.
 */
abstract class BaseLiveChatPublicAction extends BaseAction
{
    public const SESSION_HEADER = 'X-Chat-Session';

    public function __construct(
        protected readonly PublicLiveChatHandler $handler,
    ) {}

    protected function sessionId(Request $request): string
    {
        return mb_substr(trim((string) $request->header(self::SESSION_HEADER, '')), 0, 64);
    }

    protected function ip(Request $request): string
    {
        return (string) ($this->getClientIp($request) ?? '');
    }

    protected function limitResponse(LiveChatLimitReachedException $exception): JsonResponse
    {
        return $this->jsonResponse([
            'message' => $exception->getMessage(),
            'limit' => $exception->limit,
            'contact_form_fallback' => true,
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }
}
