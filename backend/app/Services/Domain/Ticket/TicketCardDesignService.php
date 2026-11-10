<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\Exceptions\InvalidTicketCardDesignException;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\FilesystemManager;
use Imagick;
use Throwable;

/**
 * Stores the invitation card of an event (event_settings.ticket_card_design) and its files on the private disk.
 */
class TicketCardDesignService
{
    private const STORAGE_DIRECTORY = 'ticket-cards';

    private const FONT_SIGNATURES = ["\x00\x01\x00\x00", 'OTTO', 'true'];

    private const COLOR_KEYS = ['qr', 'text', 'accent', 'muted', 'separator'];

    private const FONT_KEYS = ['bold', 'regular', 'light'];

    public function __construct(
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly FilesystemManager $filesystemManager,
        private readonly TicketCardRenderer $cardRenderer,
        private readonly Repository $config,
    ) {}

    /**
     * @return array<string, mixed>|null
     *
     * @throws InvalidTicketCardDesignException
     */
    public function current(int $eventId): ?array
    {
        $design = $this->settings($eventId)->getTicketCardDesign();
        if (is_string($design)) {
            $design = json_decode($design, true);
        }

        return is_array($design) ? $design : null;
    }

    /**
     * @param  array{background?: string, fonts?: array<string, string>, frame?: array{x: int, y: int, width: int, height: int}|null, colors?: array<string, string>, guests?: int, dress_code?: string, footer?: string|null}  $changes
     * @return array<string, mixed>
     *
     * @throws InvalidTicketCardDesignException
     */
    public function store(int $eventId, array $changes): array
    {
        $design = $this->current($eventId) ?? [];
        $disk = (string) ($design['disk'] ?? $this->config->get('filesystems.private'));
        $design['disk'] = $disk;

        if (isset($changes['background'])) {
            $design['background_path'] = $this->upload($eventId, $disk, $changes['background'], $this->assertImage(...));
            if (! array_key_exists('frame', $changes)) {
                $design['frame'] = $this->detectFrame($changes['background']);
            }
        }

        foreach ($changes['fonts'] ?? [] as $weight => $path) {
            if (! in_array($weight, self::FONT_KEYS, true)) {
                throw new InvalidTicketCardDesignException(__('Unknown font weight :weight.', ['weight' => $weight]));
            }
            $design['fonts'][$weight] = $this->upload($eventId, $disk, $path, $this->assertFont(...));
        }

        if (array_key_exists('frame', $changes)) {
            $design['frame'] = $changes['frame'];
        }

        foreach ($changes['colors'] ?? [] as $key => $color) {
            if (! in_array($key, self::COLOR_KEYS, true) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                throw new InvalidTicketCardDesignException(__('Invalid colour :key = :value.', ['key' => $key, 'value' => $color]));
            }
            $design['colors'][$key] = strtolower($color);
        }

        foreach (['guests', 'dress_code'] as $key) {
            if (array_key_exists($key, $changes)) {
                $design[$key] = $changes[$key];
            }
        }

        if (array_key_exists('footer', $changes)) {
            if ($changes['footer'] === null) {
                unset($design['footer']);
            } else {
                $design['footer'] = $changes['footer'];
            }
        }

        if (empty($design['background_path'])) {
            throw new InvalidTicketCardDesignException(__('A background picture is required for an invitation card.'));
        }

        $this->save($eventId, $design);

        return $design;
    }

    /**
     * @throws InvalidTicketCardDesignException
     */
    public function remove(int $eventId): void
    {
        $this->settings($eventId);
        $this->save($eventId, null);
    }

    /**
     * @param  array<string, mixed>|null  $design
     */
    private function save(int $eventId, ?array $design): void
    {
        $this->eventSettingsRepository->updateWhere(
            [EventSettingDomainObjectAbstract::TICKET_CARD_DESIGN => $design === null ? null : json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            [EventSettingDomainObjectAbstract::EVENT_ID => $eventId],
        );
    }

    /**
     * @throws InvalidTicketCardDesignException
     */
    private function settings(int $eventId): EventSettingDomainObject
    {
        $settings = $this->eventSettingsRepository->findFirstWhere([EventSettingDomainObjectAbstract::EVENT_ID => $eventId]);
        if ($settings === null) {
            throw new InvalidTicketCardDesignException(__('No settings found for event :id.', ['id' => $eventId]));
        }

        return $settings;
    }

    /**
     * @throws InvalidTicketCardDesignException
     */
    private function upload(int $eventId, string $disk, string $localPath, callable $validate): string
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new InvalidTicketCardDesignException(__('File not found: :path', ['path' => $localPath]));
        }

        $content = (string) file_get_contents($localPath);
        $validate($content, $localPath);

        $name = strtolower((string) preg_replace('/[^A-Za-z0-9.]+/', '-', basename($localPath)));
        $path = self::STORAGE_DIRECTORY.'/event-'.$eventId.'/'.substr(sha1($content), 0, 10).'-'.$name;
        $this->filesystemManager->disk($disk)->put($path, $content);

        return $path;
    }

    /**
     * @throws InvalidTicketCardDesignException
     */
    private function assertImage(string $content, string $localPath): void
    {
        try {
            $image = new Imagick;
            $image->readImageBlob($content);
            $image->clear();
        } catch (Throwable) {
            throw new InvalidTicketCardDesignException(__('This file is not a valid picture: :path', ['path' => $localPath]));
        }
    }

    /**
     * @throws InvalidTicketCardDesignException
     */
    private function assertFont(string $content, string $localPath): void
    {
        if (! in_array(substr($content, 0, 4), self::FONT_SIGNATURES, true)) {
            throw new InvalidTicketCardDesignException(__('This file is not a TTF or OTF font: :path', ['path' => $localPath]));
        }
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}
     *
     * @throws InvalidTicketCardDesignException
     */
    private function detectFrame(string $localPath): array
    {
        $image = new Imagick($localPath);
        $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $frame = $this->cardRenderer->detectFrame($image);
        $image->clear();

        if ($frame === null) {
            throw new InvalidTicketCardDesignException(__('No white box found in the lower half of the picture. Give its position with --frame.'));
        }

        return $frame;
    }
}
