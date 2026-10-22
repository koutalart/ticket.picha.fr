<?php

namespace HiEvents\DomainObjects\Enums;

enum EventArtistKind
{
    use BaseEnum;

    case PERSON;
    case GROUP;
}
