<?php

namespace HiEvents\DomainObjects\Enums;

enum NewsletterSubscriberSource
{
    use BaseEnum;

    case FOOTER;
    case REGISTRATION;
}
