<?php

namespace HiEvents\DomainObjects\Enums;

enum OrganizerFontWeight: int
{
    use BaseEnum;

    case Light = 300;
    case Regular = 400;
    case Medium = 500;
    case Bold = 700;
    case Black = 900;
}
