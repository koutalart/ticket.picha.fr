<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\LiveChat\Refund\Approval\RefundApprovalService;
use Illuminate\Console\Command;

class ProcessRefundApprovalsCommand extends Command
{
    protected $signature = 'live-chat:refund-approvals';

    protected $description = 'Send the due reminders to the validators, news to the customers and the 24 hour alerts of the refund requests waiting for approval';

    public function handle(RefundApprovalService $service): int
    {
        $result = $service->processDue();

        if ($result->validatorReminders + $result->customerUpdates + $result->escalations > 0) {
            $this->info(sprintf(
                'Validators notified: %d, customers informed: %d, alerts after 24 hours: %d',
                $result->validatorReminders,
                $result->customerUpdates,
                $result->escalations,
            ));
        }

        return self::SUCCESS;
    }
}
