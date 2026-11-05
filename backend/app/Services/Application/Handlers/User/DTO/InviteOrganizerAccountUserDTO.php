<?php

namespace HiEvents\Services\Application\Handlers\User\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\Role;

class InviteOrganizerAccountUserDTO extends BaseDataObject
{
    public function __construct(
        public int $organizer_id,
        public string $email,
        public Role $role,
        public ?string $first_name = null,
        public ?string $last_name = null,
        public bool $allow_admin = false,
        public bool $resend = false,
        public bool $dry_run = false,
    ) {}
}
