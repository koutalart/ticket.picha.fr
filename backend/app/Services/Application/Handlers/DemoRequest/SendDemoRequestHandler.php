<?php

namespace HiEvents\Services\Application\Handlers\DemoRequest;

use HiEvents\Mail\DemoRequest\DemoRequestEmail;
use HiEvents\Services\Application\Handlers\DemoRequest\DTO\SendDemoRequestDTO;
use Illuminate\Config\Repository;
use Illuminate\Mail\Mailer;

class SendDemoRequestHandler
{
    public function __construct(
        private readonly Mailer     $mailer,
        private readonly Repository $config,
    )
    {
    }

    public function handle(SendDemoRequestDTO $dto): void
    {
        $this->mailer
            ->to($this->config->get('app.demo_request_email'))
            ->send(new DemoRequestEmail(
                firstName: $dto->first_name,
                lastName: $dto->last_name,
                email: $dto->email,
                organization: $dto->organization,
                eventType: $dto->event_type,
                eventDate: $dto->event_date,
                attendeeCount: $dto->attendee_count,
            ));
    }
}
