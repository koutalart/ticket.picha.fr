<?php

namespace HiEvents\DomainObjects\Enums;

enum Role: string
{
    use BaseEnum;

    case SUPERADMIN = 'SUPERADMIN';
    case ADMIN = 'ADMIN';
    case ORGANIZER = 'ORGANIZER';

    /**
     * Box office operator (PICHA Kiosk v2, D23). Deliberately NOT in
     * getAssignableRoles(): an operator is created only through the dedicated
     * ADMIN-only flow (CreateBoxOfficeOperatorAction), never the account-wide
     * user invitation modal. Its access is scoped per event via
     * event_box_office_operators and closed everywhere else by
     * IsAuthorizedService::validateUserRole().
     */
    case BOX_OFFICE_OPERATOR = 'BOX_OFFICE_OPERATOR';

    public static function getAssignableRoles(): array
    {
        return [
            self::ADMIN->value,
            self::ORGANIZER->value,
        ];
    }
}
