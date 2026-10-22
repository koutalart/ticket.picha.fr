<?php

declare(strict_types=1);

namespace HiEvents\Helper;

use Illuminate\Support\Str;

/**
 * A short SMS that only acknowledges (« ok », « merci », « d'accord », « 👍 ») and does not call for an answer.
 */
final class SmsAcknowledgementDetector
{
    private const MAX_LENGTH = 40;

    private const ACKNOWLEDGEMENTS = [
        'ok', 'okay', 'oki', 'okey', 'okk', 'okok', 'k',
        'merci', 'mercii', 'mrc', 'mci', 'thanks', 'thank', 'thx', 'misaotra',
        'daccord', 'dac', 'dacc', 'dak', 'accord',
        'super', 'top', 'parfait', 'cool', 'genial', 'nickel', 'excellent', 'impeccable', 'bravo',
        'recu', 'entendu', 'compris', 'note', 'marche', 'vu',
    ];

    private const FILLERS = [
        'oui', 'ouais', 'yes', 'bien', 'tres', 'trop', 'beaucoup', 'bcp', 'you', 'ca', 'c', 'cest', 'tout', 'a',
        'vous', 'toi', 'encore', 'bonne', 'journee', 'soiree', 'bientot', 'alors', 'ah', 'oh', 'bisous',
    ];

    public static function isAcknowledgement(string $content): bool
    {
        $trimmed = trim($content);
        if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_LENGTH || str_contains($trimmed, '?')) {
            return false;
        }

        $letters = preg_replace('/[^\p{L}\p{N}\s\']+/u', ' ', str_replace(['’', '‘'], "'", $trimmed)) ?? '';
        $normalized = Str::lower(Str::ascii($letters));
        $normalized = str_replace("'", '', $normalized);
        $normalized = preg_replace('/[^a-z0-9 ]+/', ' ', $normalized) ?? '';
        $normalized = preg_replace('/([a-z])\1{2,}/', '$1', $normalized) ?? '';

        $words = preg_split('/\s+/', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return true;
        }

        $hasAcknowledgement = false;
        foreach ($words as $word) {
            if (in_array($word, self::ACKNOWLEDGEMENTS, true)) {
                $hasAcknowledgement = true;

                continue;
            }

            if (! in_array($word, self::FILLERS, true)) {
                return false;
            }
        }

        return $hasAcknowledgement;
    }
}
