<?php

namespace HiEvents\Mail;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Services\Domain\Mail\EmailLogoResolver;
use HiEvents\Services\Domain\Mail\OrganizerMailSenderResolver;
use HiEvents\Services\Domain\Mail\OutboundLinkShortener;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use HiEvents\Services\Domain\Sms\SmsShortLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

abstract class BaseMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected ?EventDomainObject $emailLogoEvent = null;

    protected ?OrganizerDomainObject $emailLogoOrganizer = null;

    protected ?OrganizerDomainObject $shortLinkOrganizer = null;

    public function __construct()
    {
        $this->afterCommit();
    }

    abstract public function envelope(): Envelope;

    abstract public function content(): Content;

    private function customDomainLogoLinkUrl(mixed $owner): ?string
    {
        if (! $owner instanceof EventDomainObject && ! $owner instanceof OrganizerDomainObject) {
            return null;
        }

        $baseUrl = Url::publicBaseUrl($owner);

        return $baseUrl !== app(PublicBaseUrlResolver::class)->platformUrl() ? $baseUrl : null;
    }

    /**
     * Customer e-mails attached to an event or an organizer never carry a long link: each one becomes the organizer's /q/ link.
     */
    public function send($mailer)
    {
        $this->withSymfonyMessage(fn (Email $message) => $this->shortenOutboundLinks($message));

        return parent::send($mailer);
    }

    private function shortenOutboundLinks(Email $message): void
    {
        $event = $this->emailLogoEvent;
        $organizer = $this->shortLinkOrganizer;
        $organizerId = $event?->getOrganizerId() ?? $organizer?->getId();
        $accountId = $event?->getAccountId() ?? $organizer?->getAccountId();
        if ($organizerId === null || $accountId === null) {
            return;
        }

        try {
            $resolver = app(PublicBaseUrlResolver::class);
            $hosts = array_unique(array_filter([
                parse_url($resolver->platformUrl(), PHP_URL_HOST),
                parse_url($resolver->forOrganizerId($organizerId), PHP_URL_HOST),
            ]));
            $shortLinks = app(SmsShortLinkService::class);
            $title = (string) ($event?->getTitle() ?? $organizer?->getName());
            $shorten = static fn (string $url): string => $shortLinks->shortenFor($accountId, $organizerId, $event?->getId(), $title, $url);
            $shortener = app(OutboundLinkShortener::class);

            $html = $message->getHtmlBody();
            if (is_string($html)) {
                $message->html($shortener->rewrite($html, $hosts, $shorten, true), $message->getHtmlCharset() ?? 'utf-8');
            }

            $text = $message->getTextBody();
            if (is_string($text)) {
                $message->text($shortener->rewrite($text, $hosts, $shorten, false), $message->getTextCharset() ?? 'utf-8');
            }
        } catch (Throwable $exception) {
            app(LoggerInterface::class)->warning('E-mail sent with its long links: short links failed', [
                'mail' => static::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function organizerSender(?int $organizerId): ?Address
    {
        return app(OrganizerMailSenderResolver::class)->resolveForOrganizer($organizerId);
    }

    protected function useEventEmailLogo(EventDomainObject $event, ?OrganizerDomainObject $organizer = null): void
    {
        $this->emailLogoEvent = $event;
        $this->emailLogoOrganizer = $organizer;
    }

    public function buildViewData()
    {
        $data = parent::buildViewData();
        $data['logoLinkUrl'] ??= $this->customDomainLogoLinkUrl($data['event'] ?? $data['organizer'] ?? $this->emailLogoEvent);

        if ($this->emailLogoEvent !== null && ! array_key_exists('emailLogo', $data)) {
            try {
                $data['emailLogo'] = app(EmailLogoResolver::class)->resolveForEvent(
                    $this->emailLogoEvent->getId(),
                    $this->emailLogoEvent->getOrganizerId(),
                    $this->emailLogoOrganizer?->getName(),
                );
            } catch (Throwable) {
                $data['emailLogo'] = null;
            }
        }

        return $data;
    }
}
