<?php

namespace HiEvents\Mail;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Services\Domain\Mail\OrganizerMailSenderResolver;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

abstract class BaseMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct()
    {
        $this->afterCommit();
    }

    abstract public function envelope(): Envelope;

    abstract public function content(): Content;

    public function buildViewData()
    {
        $data = parent::buildViewData();
        $data['logoLinkUrl'] ??= $this->customDomainLogoLinkUrl($data['event'] ?? $data['organizer'] ?? null);

        return $data;
    }

    private function customDomainLogoLinkUrl(mixed $owner): ?string
    {
        if (! $owner instanceof EventDomainObject && ! $owner instanceof OrganizerDomainObject) {
            return null;
        }

        $baseUrl = Url::publicBaseUrl($owner);

        return $baseUrl !== app(PublicBaseUrlResolver::class)->platformUrl() ? $baseUrl : null;
    }

    protected function organizerSender(?int $organizerId): ?Address
    {
        return app(OrganizerMailSenderResolver::class)->resolveForOrganizer($organizerId);
    }
}
