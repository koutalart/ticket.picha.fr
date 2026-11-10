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

    private const LINK_AFTER_LABEL_PATTERN = '/:([ \t]*)\{\s*lien\s*\}/iu';

    private const LINK_PATTERN = '/\{\s*lien\s*\}/iu';

    private const LOCALE = 'fr';

    /**
     * The text of the SMS, never the link itself: the link goes alone in the next SMS (see linkMessage).
     * With a link, its label says it follows ("Gérer ma venue : lien ci-dessous"); without one, the label goes too.
     */
    public function render(string $template, EventDomainObject $event, ?string $firstName, ?string $personalLink): string
    {
        $start = $this->eventStart($event);
        $values = [
            'prenom' => trim((string) $firstName),
            'evenement' => trim($event->getTitle()),
            'date' => $start?->isoFormat('dddd D MMMM') ?? '',
            'heure' => $start?->format('H\hi') ?? '',
        ];

        if (trim((string) $personalLink) === '') {
            $template = preg_replace(self::LABELLED_LINK_PATTERN, '', $template) ?? $template;
        } else {
            $template = preg_replace(self::LINK_AFTER_LABEL_PATTERN, ':$1'.__('link below', [], self::LOCALE), $template) ?? $template;
        }

        $template = preg_replace(self::LINK_PATTERN, '', $template) ?? $template;

        $rendered = preg_replace_callback(self::PATTERN, function (array $match) use ($values) {
            return $values[$this->key($match[1])] ?? $match[0];
        }, $template) ?? $template;

        return $this->tidy($rendered);
    }

    /**
     * The second SMS: the link alone, when the template asks for it and there is one.
     */
    public function linkMessage(string $template, ?string $personalLink): ?string
    {
        $link = trim((string) $personalLink);

        return $link !== '' && $this->usesPersonalLink($template) ? $link : null;
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
