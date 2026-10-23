<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Attendee\AttendanceAccess\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\AttendanceAccessChannel;

class AttendanceAccessContactDTO extends BaseDataObject
{
    public function __construct(
        public readonly AttendanceAccessChannel $channel,
        public readonly string $value,
    ) {}

    public function isEmail(): bool
    {
        return $this->channel === AttendanceAccessChannel::EMAIL;
    }

    public function masked(): string
    {
        if ($this->isEmail()) {
            [$local, $domain] = array_pad(explode('@', $this->value, 2), 2, '');
            $dot = strrpos($domain, '.');
            $extension = $dot === false ? '' : substr($domain, $dot);

            return mb_substr($local, 0, 1).'***@'.mb_substr($domain, 0, 1).'***'.$extension;
        }

        return substr($this->value, 0, 4).str_repeat('*', max(0, strlen($this->value) - 6)).substr($this->value, -2);
    }
}
