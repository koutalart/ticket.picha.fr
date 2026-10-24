<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund\Approval;

use Carbon\Carbon;
use HiEvents\DomainObjects\LiveChatRefundRequestDomainObject;
use Illuminate\Config\Repository as Config;

/**
 * Signed links to approve or refuse a refund request without being logged in. A link is valid 72 hours and only once:
 * the first decision, by any channel, closes every link of the request.
 */
class RefundApprovalLinkService
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function token(int $requestId, ?Carbon $now = null): string
    {
        $expires = ($now ?? Carbon::now('UTC'))->copy()->addHours(RefundApprovalSchedule::LINK_VALIDITY_HOURS)->getTimestamp();

        return $expires.'.'.$this->signature($requestId, $expires);
    }

    public function url(int $requestId, ?Carbon $now = null): string
    {
        return rtrim((string) $this->config->get('app.frontend_url'), '/')
            .'/refund-approval/'.$requestId.'?token='.$this->token($requestId, $now);
    }

    public function isValid(LiveChatRefundRequestDomainObject $request, ?string $token, ?Carbon $now = null): bool
    {
        if ($token === null || preg_match('/^(\d{9,12})\.([a-f0-9]{64})$/', $token, $matches) !== 1) {
            return false;
        }

        $expires = (int) $matches[1];
        if ($expires < ($now ?? Carbon::now('UTC'))->getTimestamp()) {
            return false;
        }

        return hash_equals($this->signature($request->getId(), $expires), $matches[2]);
    }

    private function signature(int $requestId, int $expires): string
    {
        return hash_hmac('sha256', 'refund-approval|'.$requestId.'|'.$expires, (string) $this->config->get('app.key'));
    }
}
