<?php

namespace HiEvents\Services\Application\Handlers\User\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\AccountInvitationOutcome;
use HiEvents\DomainObjects\Enums\Role;

class OrganizerAccountInvitationResultDTO extends BaseDataObject
{
    public function __construct(
        public AccountInvitationOutcome $outcome,
        public int $account_id,
        public string $account_name,
        public int $organizer_id,
        public string $organizer_name,
        public string $email,
        public Role $role,
        public int $inviter_id,
        public string $inviter_email,
        public bool $inviter_is_owner,
        public ?int $user_id = null,
        public ?string $member_status = null,
        public ?string $member_role = null,
    ) {}
}
