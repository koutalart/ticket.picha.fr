<?php

declare(strict_types=1);

namespace HiEvents\Helper;

/**
 * Phones send a reaction to a message (a heart, a thumbs up, an iMessage « tapback ») as a text SMS such as
 * « A aimé « … » », « A ajouté un « J'adore » à « … » », « Liked “…” » or « 👍 ». Nobody answers a reaction.
 */
final class SmsReactionDetector
{
    private const QUOTE = '[«"“”„\'‘’‹›]';

    private const CLOSING_QUOTE = '[»"“”\'‘’›]';

    private const APOSTROPHE = '[\'’‘`]';

    private const REACTION_VERBS = [
        'a\s+ajouté\s+(?:un|une|l'.self::APOSTROPHE.'|le|la)?\s*.+?\s+(?:à|a|au|sur)',
        'a\s+retiré\s+(?:un|une|l'.self::APOSTROPHE.'|le|la)?\s*.+?\s+(?:de|du|d'.self::APOSTROPHE.'|à|a|au|sur)',
        'a\s+réagi(?:\s+avec\s+.+?\s+(?:à|a|au))?',
        'a\s+aimé',
        'a\s+adoré',
        'a\s+ri\s+de',
        'a\s+souligné',
        'a\s+mis\s+en\s+évidence',
        'a\s+mis\s+en\s+doute',
        'a\s+remis\s+en\s+question',
        'a\s+mis\s+un\s+point\s+d'.self::APOSTROPHE.'exclamation\s+à',
        'a\s+mis\s+un\s+point\s+d'.self::APOSTROPHE.'interrogation\s+à',
        'n'.self::APOSTROPHE.'a\s+pas\s+aimé',
        'n'.self::APOSTROPHE.'aime\s+pas',
        'reacted(?:\s+.+?\s+to)?',
        'removed\s+(?:an?|the)?\s*.+?\s+from',
        'liked',
        'loved',
        'laughed\s+at',
        'emphasi[sz]ed',
        'questioned',
        'disliked',
    ];

    public static function isReaction(string $content): bool
    {
        $trimmed = trim($content);

        if ($trimmed === '' || preg_match('/[\p{L}\p{N}]/u', $trimmed) !== 1) {
            return true;
        }

        $pattern = '/^(?:'.implode('|', self::REACTION_VERBS).')\s*'.self::QUOTE.'.*'.self::CLOSING_QUOTE.'[\s.…]*$/isu';

        return preg_match($pattern, $trimmed) === 1;
    }
}
