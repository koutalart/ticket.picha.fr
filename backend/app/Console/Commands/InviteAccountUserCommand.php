<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Enums\AccountInvitationOutcome;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\AccountInviterNotFoundException;
use HiEvents\Exceptions\InvitationRoleNotAllowedException;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Services\Application\Handlers\User\DTO\InviteOrganizerAccountUserDTO;
use HiEvents\Services\Application\Handlers\User\DTO\OrganizerAccountInvitationResultDTO;
use HiEvents\Services\Application\Handlers\User\InviteOrganizerAccountUserHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class InviteAccountUserCommand extends Command
{
    protected $signature = 'account:invite-user
        {organizer : Organizer ID, the invitation is sent on behalf of its account}
        {email : E-mail address of the person to invite}
        {--first-name= : First name (defaults to the part of the e-mail before @)}
        {--last-name= : Last name}
        {--role=ORGANIZER : Role given in the account}
        {--allow-admin : Required to invite with the ADMIN role}
        {--resend : Send the invitation again when one is already pending}
        {--dry-run : Show what would happen without sending anything}';

    protected $description = 'Invite a user to the account of an organizer, exactly like Settings > Users > Invite';

    public function __construct(
        private readonly InviteOrganizerAccountUserHandler $handler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $allowAdmin = (bool) $this->option('allow-admin');

        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            return $this->failWith(__('Invalid e-mail address: :email', ['email' => $email]), 'invalid_email');
        }

        $role = Role::tryFrom(strtoupper(trim((string) $this->option('role'))));

        if ($role === null) {
            return $this->failWith(__('Unknown role. Allowed roles: :roles', [
                'roles' => implode(', ', array_map(
                    static fn (Role $role) => $role->value,
                    InviteOrganizerAccountUserHandler::allowedRoles($allowAdmin),
                )),
            ]), 'invalid_role');
        }

        try {
            $result = $this->handler->handle(new InviteOrganizerAccountUserDTO(
                organizer_id: (int) $this->argument('organizer'),
                email: $email,
                role: $role,
                first_name: $this->option('first-name'),
                last_name: $this->option('last-name'),
                allow_admin: $allowAdmin,
                resend: (bool) $this->option('resend'),
                dry_run: (bool) $this->option('dry-run'),
            ));
        } catch (OrganizerNotFoundException $exception) {
            return $this->failWith($exception->getMessage(), 'organizer_not_found');
        } catch (InvitationRoleNotAllowedException $exception) {
            return $this->failWith($exception->getMessage(), 'role_not_allowed');
        } catch (AccountInviterNotFoundException $exception) {
            return $this->failWith($exception->getMessage(), 'no_inviter');
        } catch (ResourceConflictException $exception) {
            return $this->failWith($exception->getMessage(), 'conflict');
        }

        $this->describe($result);
        $this->line($this->statusLine($result));

        return self::SUCCESS;
    }

    private function describe(OrganizerAccountInvitationResultDTO $result): void
    {
        $this->line(__('Account: :name (#:id)', ['name' => $result->account_name, 'id' => $result->account_id]));
        $this->line(__('Organizer: :name (#:id)', ['name' => $result->organizer_name, 'id' => $result->organizer_id]));
        $this->line(__('E-mail: :email', ['email' => $result->email]));
        $this->line(__('Role: :role', ['role' => $result->role->value]));
        $this->line(__('Invited by: :email (#:id, :kind)', [
            'email' => $result->inviter_email,
            'id' => $result->inviter_id,
            'kind' => $result->inviter_is_owner ? __('account owner') : __('first active admin'),
        ]));

        match ($result->outcome) {
            AccountInvitationOutcome::INVITED => $this->info(__('Invitation sent.')),
            AccountInvitationOutcome::INVITATION_RESENT => $this->info(__('Pending invitation sent again.')),
            AccountInvitationOutcome::ALREADY_MEMBER => $this->warn(__('This user is already a member of the account (status :status, role :role). Nothing was sent.', [
                'status' => $result->member_status,
                'role' => $result->member_role,
            ])),
            AccountInvitationOutcome::ALREADY_INVITED => $this->warn(__('An invitation is already pending for this user. Nothing was sent (use --resend to send it again).')),
            AccountInvitationOutcome::DRY_RUN => $this->warn(__('Dry run: nothing was sent.')),
        };
    }

    private function statusLine(OrganizerAccountInvitationResultDTO $result): string
    {
        $fields = [
            'user_id' => $result->user_id ?? '-',
            'account_id' => $result->account_id,
            'organizer_id' => $result->organizer_id,
            'email' => $result->email,
            'role' => $result->outcome === AccountInvitationOutcome::ALREADY_MEMBER || $result->outcome === AccountInvitationOutcome::ALREADY_INVITED
                ? $result->member_role
                : $result->role->value,
            'inviter_id' => $result->inviter_id,
        ];

        if ($result->member_status !== null) {
            $fields['status'] = $result->member_status;
        }

        return $result->outcome->value.' '.implode(' ', array_map(
            static fn (string $key, mixed $value) => $key.'='.$value,
            array_keys($fields),
            $fields,
        ));
    }

    private function failWith(string $message, string $reason): int
    {
        $this->error($message);
        $this->line('ERROR reason='.$reason);

        return self::FAILURE;
    }
}
