<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum OrganizerSection: string
{
    use BaseEnum;

    case DASHBOARD = 'dashboard';
    case EVENTS = 'events';
    case CONTACTS = 'contacts';
    case REPORTS = 'reports';
    case QR_CODES = 'qr-codes';
    case SMS = 'sms';
    case PUBLICATIONS = 'publications';
}
