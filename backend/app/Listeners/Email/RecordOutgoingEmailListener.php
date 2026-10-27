<?php

declare(strict_types=1);

namespace HiEvents\Listeners\Email;

use HiEvents\Services\Domain\Email\Inbox\OutgoingEmailRecorder;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;

class RecordOutgoingEmailListener
{
    public function __construct(
        private readonly OutgoingEmailRecorder $recorder,
    ) {}

    public function handleSending(MessageSending $event): void
    {
        $this->recorder->prepare($event->message, $event->data);
    }

    public function handleSent(MessageSent $event): void
    {
        $this->recorder->recordSent($event->sent);
    }
}
