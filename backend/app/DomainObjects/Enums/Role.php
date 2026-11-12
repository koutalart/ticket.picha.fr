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

    /**
     * Read-only viewer of specific events. Deliberately NOT in
     * getAssignableRoles(): granted only with the event-viewer:grant command.
     * Its access is scoped per event via event_viewers and limited by
     * IsAuthorizedService to an allowlist of read and export endpoints.
     */
    case EVENT_VIEWER = 'EVENT_VIEWER';

    public static function getAssignableRoles(): array
    {
        return [
            self::ADMIN->value,
            self::ORGANIZER->value,
        ];
    }
}
