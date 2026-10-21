<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsAssistantCallDomainObject;
use HiEvents\Models\SmsAssistantCall;
use HiEvents\Repository\Interfaces\SmsAssistantCallRepositoryInterface;

/**
 * @extends BaseRepository<SmsAssistantCallDomainObject>
 */
class SmsAssistantCallRepository extends BaseRepository implements SmsAssistantCallRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsAssistantCall::class;
    }

    public function getDomainObject(): string
    {
        return SmsAssistantCallDomainObject::class;
    }
}
