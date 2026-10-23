<?php

namespace HiEvents\DomainObjects\Enums;

enum OrganizerFontFormat: string
{
    use BaseEnum;

    case WOFF2 = 'woff2';
    case WOFF = 'woff';
    case TRUETYPE = 'truetype';
    case OPENTYPE = 'opentype';

    public const ALLOWED_EXTENSIONS = ['woff2', 'woff', 'ttf', 'otf'];

    public function fileExtension(): string
    {
        return match ($this) {
            self::WOFF2 => 'woff2',
            self::WOFF => 'woff',
            self::TRUETYPE => 'ttf',
            self::OPENTYPE => 'otf',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::WOFF2 => 'font/woff2',
            self::WOFF => 'font/woff',
            self::TRUETYPE => 'font/ttf',
            self::OPENTYPE => 'font/otf',
        };
    }

    public static function fromSignature(string $firstBytes): ?self
    {
        $signature = substr($firstBytes, 0, 4);

        return match ($signature) {
            'wOF2' => self::WOFF2,
            'wOFF' => self::WOFF,
            'OTTO' => self::OPENTYPE,
            "\x00\x01\x00\x00", 'true' => self::TRUETYPE,
            default => null,
        };
    }

    /**
     * TTF and OTF are both SFNT containers: an .otf can hold TrueType outlines and vice versa.
     */
    public function matchesExtension(string $extension): bool
    {
        $extension = strtolower($extension);

        return match ($this) {
            self::WOFF2 => $extension === 'woff2',
            self::WOFF => $extension === 'woff',
            self::TRUETYPE, self::OPENTYPE => in_array($extension, ['ttf', 'otf'], true),
        };
    }
}
