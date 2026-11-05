<?php

namespace HiEvents\DomainObjects\Enums;

enum AccountInvitationOutcome: string
{
    use BaseEnum;

    case INVITED = 'INVITED';
    case INVITATION_RESENT = 'INVITATION_RESENT';
    case ALREADY_MEMBER = 'ALREADY_MEMBER';
    case ALREADY_INVITED = 'ALREADY_INVITED';
    case DRY_RUN = 'DRY_RUN';
}
