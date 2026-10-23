<?php

declare(strict_types=1);

namespace HiEvents\Helper;

use Illuminate\Support\Str;

/**
 * Topics an automatic reply must never handle: the SMS stays a draft for the team. Deliberately broad.
 */
final class SmsSensitiveTopicDetector
{
    private const PATTERNS = [
        'complaint' => '/\b(reclam|plainte|plaind|mecontent|inadmissible|scandal|honte|arnaque|escroc|voleur|vol\b|litige|avocat|police|gendarm|tribunal|justice|signaler|insult|menace|nul\b|decu|decevant|probleme|souci|erreur|bug|pas recu|jamais recu|toujours pas|introuvable|bloque|debite|preleve|deux fois|en double)/',
        'refund' => '/\b(rembours|refund|annul|cancel|echang|revend|revente|retract|geste commercial|dedommag)/',
        'invitation' => '/\b(invit|guest|gratuit|gratos|offert|offrir|cadeau|concours|gagner|gagne|exonere|faveur|vip|backstage|accreditation|accredit|partenariat|sponsor|prestat|benevol)/',
        'place_request' => '/\b(donner|offrir|obtenir|garder|reserver|mettre de cote|filer|passer)\b.{0,30}\bplaces?\b|\bplaces?\b.{0,20}\b(gratuit|offert|pour moi|pour nous|svp|stp|sil (te|vous) plait)/',
        'press' => '/\b(presse|journalist|journal\b|media|medias|interview|reportage|reporter|redaction|tele\b|television|radio|chaine|photographe|tournage|filmer)/',
        'minor' => '/\b(mineur|mineure|enfant|gamin|fils\b|fille\b|filles\b|neveu|niece|bebe|ado\b|ados\b|adolescent|college|lycee|lyceen|age minimum|moins de \d+|(ai|a|as|de|age de|agee de) \d{1,2} ?ans\b|autorisation parentale|parent)/',
        'accessibility' => '/\b(pmr|handicap|fauteuil|mobilite reduite|accessib|malvoyant|aveugle|sourd|malentendant|bequille|ascenseur|rampe|assistance medicale|medical|malade|enceinte)/',
        'human' => '/\b(parler a (quelqu|un|une|la|le)|appele[rz]|rappele[rz]|telephone[rz]?|responsable|directeur|patron|gerant|humain|une vraie personne)/',
    ];

    public static function detect(string $content): ?string
    {
        $normalized = self::normalize($content);
        if ($normalized === '') {
            return null;
        }

        foreach (self::PATTERNS as $topic => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return $topic;
            }
        }

        return null;
    }

    public static function isSensitive(string $content): bool
    {
        return self::detect($content) !== null;
    }

    private static function normalize(string $content): string
    {
        $text = Str::lower(Str::ascii($content));
        $text = str_replace(["'", '’'], '', $text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
