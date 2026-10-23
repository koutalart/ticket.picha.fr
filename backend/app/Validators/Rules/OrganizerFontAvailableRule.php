<?php

namespace HiEvents\Validators\Rules;

use Closure;
use HiEvents\DomainObjects\Enums\HomepageFontFamily;
use HiEvents\Services\Domain\Organizer\Font\OrganizerFontService;
use Illuminate\Contracts\Validation\ValidationRule;

class OrganizerFontAvailableRule implements ValidationRule
{
    private function __construct(
        private readonly ?int $organizerId,
        private readonly ?int $eventId,
    ) {}

    public static function forOrganizer(int|string|null $organizerId): self
    {
        return new self(organizerId: $organizerId !== null ? (int) $organizerId : null, eventId: null);
    }

    public static function forEvent(int|string|null $eventId): self
    {
        return new self(organizerId: null, eventId: $eventId !== null ? (int) $eventId : null);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== HomepageFontFamily::OrganizerFont->value) {
            return;
        }

        /** @var OrganizerFontService $service */
        $service = app(OrganizerFontService::class);

        $available = match (true) {
            $this->organizerId !== null => $service->isAvailableForOrganizer($this->organizerId),
            $this->eventId !== null => $service->isAvailableForEvent($this->eventId),
            default => false,
        };

        if (! $available) {
            $fail(__('Upload at least the Regular (400) file of the organizer font before selecting it.'));
        }
    }
}
