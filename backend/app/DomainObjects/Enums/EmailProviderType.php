<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum EmailProviderType: string
{
    use BaseEnum;

    case RESEND = 'RESEND';
    case BREVO = 'BREVO';
}
