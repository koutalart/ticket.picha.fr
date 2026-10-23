<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use Carbon\Carbon;
use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\Repository\Interfaces\ContactLinkVisitRepositoryInterface;
use HiEvents\Services\Domain\Contact\DTO\ContactLinkVisitContextDTO;
use HiEvents\Services\Domain\QrLink\UserAgentParser;
use HiEvents\Services\Domain\QrLink\VisitContextNormalizer;

class ContactLinkVisitRecorder
{
    public function __construct(
        private readonly ContactLinkVisitRepositoryInterface $visitRepository,
        private readonly UserAgentParser $userAgentParser,
    ) {}

    public function record(ContactDomainObject $contact, ContactLinkVisitContextDTO $context): bool
    {
        $userAgent = $this->userAgentParser->parse($context->userAgent);

        if ($userAgent->isBot) {
            return false;
        }

        $visitorHash = hash_hmac(
            'sha256',
            'contact|'.$contact->getId().'|'.$context->ipAddress.'|'.$context->userAgent,
            (string) config('app.key'),
        );

        $this->visitRepository->create([
            'contact_id' => $contact->getId(),
            'visitor_hash' => $visitorHash,
            'is_unique' => ! $this->visitRepository->hasVisitFromVisitor($contact->getId(), $visitorHash),
            'device_type' => $userAgent->deviceType,
            'os' => $userAgent->os,
            'browser' => $userAgent->browser,
            'country_code' => VisitContextNormalizer::countryCode($context->countryCode),
            'referrer_host' => VisitContextNormalizer::referrerHost($context->referrer),
            'created_at' => Carbon::now('UTC')->toDateTimeString(),
        ]);

        return true;
    }
}
