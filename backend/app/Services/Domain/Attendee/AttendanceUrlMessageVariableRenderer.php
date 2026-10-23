<?php

namespace HiEvents\Services\Domain\Attendee;

use HiEvents\Helper\CustomerLocale;

class AttendanceUrlMessageVariableRenderer
{
    public const VARIABLE = 'attendee.attendance_url';

    private const SPACE = '(?:\s|%20|&nbsp;)*';

    private const ENCODED_PATTERN = '/(?:\{\{|%7B%7B)' . self::SPACE . 'attendee\.attendance_url' . self::SPACE . '(?:\}\}|%7D%7D)/i';

    private const TEXT_PATTERN = '/\{\{' . self::SPACE . 'attendee\.attendance_url' . self::SPACE . '\}\}/i';

    private const ATTRIBUTE_PATTERN = '/(href\s*=\s*["\'])' . self::SPACE . '(?:\{\{|%7B%7B)' . self::SPACE . 'attendee\.attendance_url' . self::SPACE . '(?:\}\}|%7D%7D)/i';

    public function containsVariable(string $html): bool
    {
        return preg_match(self::ENCODED_PATTERN, $html) === 1;
    }

    public function strip(string $html): string
    {
        $html = preg_replace(self::ATTRIBUTE_PATTERN, '$1#', $html);

        return preg_replace(self::ENCODED_PATTERN, '', $html);
    }

    /**
     * Replaces the variable with the link. A null URL renders a neutral placeholder (copy sent to the organizer).
     */
    public function render(string $html, ?string $url): string
    {
        if (!$this->containsVariable($html)) {
            return $html;
        }

        $label = __('Confirm or cancel my attendance', [], CustomerLocale::get());

        if ($url === null) {
            $placeholder = '[' . e($label) . ']';
            $html = preg_replace(self::ATTRIBUTE_PATTERN, '$1#', $html);

            return preg_replace(self::ENCODED_PATTERN, $placeholder, $html);
        }

        $escapedUrl = e($url);

        $html = preg_replace_callback(
            self::ATTRIBUTE_PATTERN,
            static fn(array $matches) => $matches[1] . $escapedUrl,
            $html,
        );

        return preg_replace_callback(
            self::TEXT_PATTERN,
            static fn() => sprintf('<a href="%s">%s</a>', $escapedUrl, e($label)),
            $html,
        );
    }
}
