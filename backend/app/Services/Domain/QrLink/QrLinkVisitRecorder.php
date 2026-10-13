<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrLink;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\QrLinkVisitType;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Repository\Interfaces\QrLinkVisitRepositoryInterface;
use HiEvents\Services\Domain\QrLink\DTO\QrLinkVisitContextDTO;

class QrLinkVisitRecorder
{
    public function __construct(
        private readonly QrLinkVisitRepositoryInterface $qrLinkVisitRepository,
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
        private readonly UserAgentParser $userAgentParser,
    ) {}

    public function record(QrLinkDomainObject $qrLink, QrLinkVisitContextDTO $context): bool
    {
        $userAgent = $this->userAgentParser->parse($context->userAgent);

        if ($userAgent->isBot) {
            return false;
        }

        $visitorHash = $this->visitorHash($qrLink->getId(), $context->ipAddress, $context->userAgent);
        $isScan = $context->type === QrLinkVisitType::SCAN;
        $isUnique = $isScan && ! $this->qrLinkVisitRepository->hasScanFromVisitor($qrLink->getId(), $visitorHash);

        $this->qrLinkVisitRepository->create([
            'qr_link_id' => $qrLink->getId(),
            'type' => $context->type->value,
            'target' => $isScan ? null : $this->normalizeTarget($context->target),
            'visitor_hash' => $visitorHash,
            'is_unique' => $isUnique,
            'device_type' => $userAgent->deviceType,
            'os' => $userAgent->os,
            'browser' => $userAgent->browser,
            'country_code' => $this->normalizeCountryCode($context->countryCode),
            'referrer_host' => $this->referrerHost($context->referrer),
            'created_at' => Carbon::now('UTC')->toDateTimeString(),
        ]);

        $this->qrLinkRepository->incrementVisitCounters($qrLink->getId(), $isScan, $isUnique);

        return true;
    }

    private function visitorHash(int $qrLinkId, ?string $ipAddress, ?string $userAgent): string
    {
        return hash_hmac('sha256', $qrLinkId.'|'.$ipAddress.'|'.$userAgent, (string) config('app.key'));
    }

    private function normalizeTarget(?string $target): ?string
    {
        if ($target === null) {
            return null;
        }

        $target = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $target));

        return $target === '' ? null : substr($target, 0, 30);
    }

    private function normalizeCountryCode(?string $countryCode): ?string
    {
        $countryCode = strtoupper(trim((string) $countryCode));

        return preg_match('/^[A-Z]{2}$/', $countryCode) && $countryCode !== 'XX' ? $countryCode : null;
    }

    private function referrerHost(?string $referrer): ?string
    {
        $host = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;

        return $host ? substr(strtolower(preg_replace('/^www\./', '', $host)), 0, 255) : null;
    }
}
