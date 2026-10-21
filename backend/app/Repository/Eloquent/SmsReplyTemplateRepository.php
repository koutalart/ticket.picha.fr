<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsReplyTemplateDomainObject;
use HiEvents\Models\SmsReplyTemplate;
use HiEvents\Repository\Interfaces\SmsReplyTemplateRepositoryInterface;

/**
 * @extends BaseRepository<SmsReplyTemplateDomainObject>
 */
class SmsReplyTemplateRepository extends BaseRepository implements SmsReplyTemplateRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsReplyTemplate::class;
    }

    public function getDomainObject(): string
    {
        return SmsReplyTemplateDomainObject::class;
    }
}
