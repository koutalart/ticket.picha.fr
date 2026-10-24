<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Social\HandleMetaOAuthCallbackHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HandleMetaOAuthCallbackActionPublic extends BaseAction
{
    public function __construct(
        private readonly HandleMetaOAuthCallbackHandler $handler,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        return $this->redirectResponse($this->handler->handle(
            code: $request->query('code') !== null ? (string) $request->query('code') : null,
            state: $request->query('state') !== null ? (string) $request->query('state') : null,
            error: $request->query('error') !== null ? (string) $request->query('error') : null,
        ));
    }
}
