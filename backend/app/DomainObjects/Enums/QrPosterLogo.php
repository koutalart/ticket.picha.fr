<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum QrPosterLogo: string
{
    use BaseEnum;

    case ORGANIZER = 'organizer';
    case WEBSITE = 'website';
    case VIDEO = 'video';
    case PODCAST = 'podcast';
    case APP = 'app';
    case INSTAGRAM = 'instagram';
    case FACEBOOK = 'facebook';
    case TIKTOK = 'tiktok';
    case WHATSAPP = 'whatsapp';
    case YOUTUBE = 'youtube';
    case X = 'x';
    case THREADS = 'threads';
    case SNAPCHAT = 'snapchat';
    case LINKEDIN = 'linkedin';
    case TELEGRAM = 'telegram';

    public function iconFile(): ?string
    {
        return match ($this) {
            self::ORGANIZER => null,
            self::WEBSITE => 'world.svg',
            self::VIDEO => 'movie.svg',
            self::PODCAST => 'microphone.svg',
            self::APP => 'device-mobile.svg',
            default => 'brand-'.$this->value.'.svg',
        };
    }
}
