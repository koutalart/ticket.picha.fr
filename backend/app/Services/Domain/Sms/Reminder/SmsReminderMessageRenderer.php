<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Reminder;

use HiEvents\DomainObjects\EventDomainObject;
use Illuminate\Support\Carbon;
use Throwable;

class SmsReminderMessageRenderer
{
    public const VARIABLES = ['prenom', 'evenement', 'date', 'heure', 'lien'];

    private const PATTERN = '/\{\s*(pr[eé]nom|[eé]v[eé]nement|date|heure|lien)\s*\}/iu';

    /**
     * A label introducing the personal link ("Gérer ma venue : {lien}"), removed with the link when there is none.
     */
    private const LABELLED_LINK_PATTERN = '/[^.!?\n:]*:[ \t]*\{\s*lien\s*\}/iu';

    private const LOCALE = 'fr';

    public function render(string $template, EventDomainObject $event, ?string $firstName, ?string $personalLink): string
    {
        $start = $this->eventStart($event);
        $values = [
            'prenom' => trim((string) $firstName),
            'evenement' => trim($event->getTitle()),
            'date' => $start?->isoFormat('dddd D MMMM') ?? '',
            'heure' => $start?->format('H\hi') ?? '',
            'lien' => trim((string) $personalLink),
        ];

        if ($values['lien'] === '') {
            $template = preg_replace(self::LABELLED_LINK_PATTERN, '', $template) ?? $template;
        }

        $rendered = preg_replace_callback(self::PATTERN, function (array $match) use ($values) {
            return $values[$this->key($match[1])] ?? $match[0];
        }, $template) ?? $template;

        return $this->tidy($rendered);
    }

    public function usesPersonalLink(string $template): bool
    {
        return preg_match_all(self::PATTERN, $template, $matches) > 0
            && in_array('lien', array_map(fn (string $name) => $this->key($name), $matches[1]), true);
    }

    private function key(string $name): string
    {
        return strtr(mb_strtolower($name), ['é' => 'e']);
    }

    private function eventStart(EventDomainObject $event): ?Carbon
    {
        if ($event->getStartDate() === null) {
            return null;
        }

        try {
            return Carbon::parse($event->getStartDate(), 'UTC')
                ->setTimezone($event->getTimezone() ?: 'UTC')
                ->locale(self::LOCALE);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Removes the gaps left by an empty variable (missing first name or personal link).
     */
    private function tidy(string $text): string
    {
        $lines = array_map(
            fn (string $line) => trim(preg_replace(['/[ \t]{2,}/u', '/[ \t]+([,.])/u', '/^([,.]\s*)/u'], [' ', '$1', ''], $line) ?? $line),
            explode("\n", str_replace("\r\n", "\n", $text)),
        );

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');
    }
}
