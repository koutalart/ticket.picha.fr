<?php

declare(strict_types=1);

namespace HiEvents\Helper;

/**
 * Phones send a reaction to a message (a heart, a thumbs up) as a text SMS such as « A aimé "…" » or « 👍 ».
 */
final class SmsReactionDetector
{
    private const QUOTED_REACTION = '/^(a\s+réagi(\s+avec\s+.+?\s+à)?|a\s+aimé|a\s+adoré|a\s+ri\s+de|a\s+souligné|a\s+mis\s+en\s+évidence|a\s+remis\s+en\s+question|n\'aime\s+pas|reacted(\s+.+?\s+to)?|liked|loved|laughed\s+at|emphasi[sz]ed|disliked|questioned)\s*[«"“”\'‘’]/iu';

    public static function isReaction(string $content): bool
    {
        $trimmed = trim($content);

        if ($trimmed === '' || preg_match('/[\p{L}\p{N}]/u', $trimmed) !== 1) {
            return true;
        }

        return preg_match(self::QUOTED_REACTION, $trimmed) === 1;
    }
}
