<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Media are referenced in the calendar JSON only under the media keys (by default "vignette" and "media"),
 * either as one string or as a list of strings. Every other value passes through untouched.
 * On import, file names become "media:{id}"; when served, "media:{id}" becomes a signed URL.
 */
class OrganizerCalendarMediaReferences
{
    public const REFERENCE_PREFIX = 'media:';

    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return string[]
     */
    public function mediaKeys(): array
    {
        $keys = $this->config->get('services.organizer_calendars.media_keys');

        return is_array($keys) && $keys !== [] ? array_values(array_map('strval', $keys)) : ['vignette', 'media'];
    }

    /**
     * Applies $map to every media reference. $map returns the new value, or null to drop it.
     *
     * @param  callable(string): ?string  $map
     */
    public function map(array $content, callable $map): array
    {
        return $this->walk($content, $map, $this->mediaKeys());
    }

    /**
     * @return string[]
     */
    public function collect(array $content): array
    {
        $found = [];
        $this->map($content, function (string $value) use (&$found): string {
            $found[] = $value;

            return $value;
        });

        return array_values(array_unique($found));
    }

    public static function reference(int $mediaId): string
    {
        return self::REFERENCE_PREFIX.$mediaId;
    }

    public static function mediaIdFromReference(string $value): ?int
    {
        return preg_match('/^'.preg_quote(self::REFERENCE_PREFIX, '/').'(\d+)$/', $value, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function walk(array $node, callable $map, array $mediaKeys): array
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, $mediaKeys, true)) {
                $node[$key] = $this->mapValue($value, $map);

                continue;
            }

            if (is_array($value)) {
                $node[$key] = $this->walk($value, $map, $mediaKeys);
            }
        }

        return $node;
    }

    private function mapValue(mixed $value, callable $map): mixed
    {
        if (is_string($value)) {
            return $value === '' ? $value : $map($value);
        }

        if (is_array($value) && array_is_list($value)) {
            $mapped = [];
            foreach ($value as $item) {
                if (! is_string($item)) {
                    $mapped[] = $item;

                    continue;
                }

                $result = $item === '' ? $item : $map($item);
                if ($result !== null) {
                    $mapped[] = $result;
                }
            }

            return $mapped;
        }

        return $value;
    }
}
