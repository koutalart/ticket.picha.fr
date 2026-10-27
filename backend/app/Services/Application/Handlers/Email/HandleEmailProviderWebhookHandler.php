<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Email;

use HiEvents\Exceptions\EmailProviderException;
use HiEvents\Exceptions\EmailWebhookUnauthorizedException;
use HiEvents\Services\Domain\Email\Inbox\EmailDeliveryStatusService;
use HiEvents\Services\Domain\Email\Inbox\EmailReplyAddressService;
use HiEvents\Services\Domain\Email\Inbox\InboundEmailService;
use HiEvents\Services\Infrastructure\EmailProvider\DTO\EmailProviderEventDTO;
use HiEvents\Services\Infrastructure\EmailProvider\EmailProviderInterface;

class HandleEmailProviderWebhookHandler
{
    public function __construct(
        private readonly EmailProviderInterface $provider,
        private readonly EmailDeliveryStatusService $deliveryStatusService,
        private readonly InboundEmailService $inboundEmailService,
        private readonly EmailReplyAddressService $replyAddressService,
    ) {}

    /**
     * @param  array<string, string|null>  $headers  lower-cased request headers
     *
     * @throws EmailWebhookUnauthorizedException
     * @throws EmailProviderException
     */
    public function handle(string $payload, array $headers): void
    {
        $this->provider->verifyWebhook($payload, $headers);

        $event = $this->provider->parseWebhook($payload);

        if ($event === null) {
            return;
        }

        if ($event->kind === EmailProviderEventDTO::KIND_STATUS) {
            $this->deliveryStatusService->apply($event);

            return;
        }

        if ($this->replyAddressService->extractCandidates($event->recipients) === []) {
            return;
        }

        $this->inboundEmailService->handle(
            $this->provider->fetchInboundEmail($event->providerEmailId),
            $this->provider->type()->name,
        );
    }
}
