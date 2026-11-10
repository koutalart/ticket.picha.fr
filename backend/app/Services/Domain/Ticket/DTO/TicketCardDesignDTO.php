<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

/**
 * An invitation card picture used instead of the label layout for the PDF and the on-screen ticket.
 * The Zebra label never uses it.
 */
class TicketCardDesignDTO extends BaseDataObject
{
    public const DEFAULT_QR_COLOR = '#06192b';

    public const DEFAULT_TEXT_COLOR = '#081a38';

    public const DEFAULT_ACCENT_COLOR = '#b08a34';

    public const DEFAULT_MUTED_COLOR = '#5f697d';

    public const DEFAULT_SEPARATOR_COLOR = '#e1e4eb';

    /**
     * @param  array{x: int, y: int, width: int, height: int}|null  $frame  White box of the picture; detected when null
     */
    public function __construct(
        public readonly string $disk,
        public readonly string $backgroundPath,
        public readonly ?array $frame = null,
        public readonly string $qrColor = self::DEFAULT_QR_COLOR,
        public readonly string $textColor = self::DEFAULT_TEXT_COLOR,
        public readonly string $accentColor = self::DEFAULT_ACCENT_COLOR,
        public readonly string $mutedColor = self::DEFAULT_MUTED_COLOR,
        public readonly string $separatorColor = self::DEFAULT_SEPARATOR_COLOR,
        public readonly ?string $fontBoldPath = null,
        public readonly ?string $fontRegularPath = null,
        public readonly ?string $fontLightPath = null,
        public readonly string $guestsLine = '',
        public readonly string $dressCodeLine = '',
        public readonly string $footerLine = '',
    ) {}

    public static function fromSettings(mixed $settings, string $defaultDisk): ?self
    {
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        if (! is_array($settings) || trim((string) ($settings['background_path'] ?? '')) === '') {
            return null;
        }

        $colors = is_array($settings['colors'] ?? null) ? $settings['colors'] : [];
        $fonts = is_array($settings['fonts'] ?? null) ? $settings['fonts'] : [];
        $guests = (int) ($settings['guests'] ?? 0);
        $dressCode = trim((string) ($settings['dress_code'] ?? ''));

        return new self(
            disk: (string) ($settings['disk'] ?? $defaultDisk),
            backgroundPath: (string) $settings['background_path'],
            frame: self::frame($settings['frame'] ?? null),
            qrColor: self::color($colors['qr'] ?? null, self::DEFAULT_QR_COLOR),
            textColor: self::color($colors['text'] ?? null, self::DEFAULT_TEXT_COLOR),
            accentColor: self::color($colors['accent'] ?? null, self::DEFAULT_ACCENT_COLOR),
            mutedColor: self::color($colors['muted'] ?? null, self::DEFAULT_MUTED_COLOR),
            separatorColor: self::color($colors['separator'] ?? null, self::DEFAULT_SEPARATOR_COLOR),
            fontBoldPath: self::path($fonts['bold'] ?? null),
            fontRegularPath: self::path($fonts['regular'] ?? null),
            fontLightPath: self::path($fonts['light'] ?? null),
            guestsLine: match (true) {
                $guests > 1 => __('Invitation valid for :count people', ['count' => $guests]),
                $guests === 1 => __('Invitation valid for 1 person'),
                default => '',
            },
            dressCodeLine: $dressCode === '' ? '' : __('Dress code: :dress_code', ['dress_code' => $dressCode]),
            footerLine: array_key_exists('footer', $settings)
                ? trim((string) $settings['footer'])
                : __('QR code and ID required at the entrance'),
        );
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private static function frame(mixed $frame): ?array
    {
        if (! is_array($frame)) {
            return null;
        }

        $values = array_map('intval', array_intersect_key($frame, array_flip(['x', 'y', 'width', 'height'])));
        if (count($values) !== 4 || $values['width'] <= 0 || $values['height'] <= 0) {
            return null;
        }

        return ['x' => $values['x'], 'y' => $values['y'], 'width' => $values['width'], 'height' => $values['height']];
    }

    private static function color(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $default;
    }

    private static function path(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
