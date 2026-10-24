<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Public;

use HiEvents\Services\Application\Handlers\LiveChat\LiveChatRefundHandler;
use HiEvents\Services\Application\Handlers\LiveChat\PublicLiveChatHandler;

abstract class BaseLiveChatRefundPublicAction extends BaseLiveChatPublicAction
{
    public function __construct(
        PublicLiveChatHandler $handler,
        protected readonly LiveChatRefundHandler $refundHandler,
    ) {
        parent::__construct($handler);
    }
}
