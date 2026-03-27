<?php

namespace App\Helpers;

/**
 * Centrale helper voor het parsen en formatteren van rennersnamen.
 *
 * PCS geeft namen terug in twee formaten:
 *   1. "POGAČAR Tadej"       → ACHTERNAAM Voornaam (ploegenroster, startlijst)
 *   2. "Tadej Pogačar"       → Voornaam Achternaam (rennerprofiel)
 *
 * Beide worden omgezet naar [voornaam, achternaam] met correcte hoofdletters.
 * Tussenvoegsels (van, de, der, ...) blijven altijd lowercase.
 *
 * Voorbeelden:
 *   "VAN DER POEL Mathieu"  → ["Mathieu", "van der Poel"]
 *   "VAN AERT Wout"         → ["Wout", "van Aert"]
 *   "POGAČAR Tadej"         → ["Tadej", "Pogačar"]
 *   "VINGEGAARD Jonas"      → ["Jonas", "Vingegaard"]
 *   "Tadej Pogačar"         → ["Tadej", "Pogačar"]
 */
class NameHelper
{
    private const PARTICLES = [
        'van', 'de', 'den', 'der', 'du', 'di', 'del', 'della',
        'le', 'la', 'los', 'von', 'zum', 'aan', 'op', 'ten',
    ];

    /**
     * Splitst een naam in [voornaam, achternaam] en past correcte
     * hoofdletters toe.
     */
    public static function parse(string $raw): array
    {
        $raw   = trim($raw);
        $words = preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($words)) {
            return ['', ''];
        }

        if (count($words) === 1) {
            return [self::ucFirst(mb_strtolower($raw)), ''];
        }

        // Detecteer PCS "ACHTERNAAM Voornaam" formaat:
        // Het eerste woord is volledig in hoofdletters (ook met accenten).
        if (mb_strtoupper($words[0]) === $words[0] && mb_strlen($words[0]) > 1) {
            // Laatste woord = voornaam, de rest = achternaam
            $firstName = array_pop($words);
            $lastName  = implode(' ', $words);
        } else {
            // Normaal "Voornaam Achternaam" formaat
            $firstName = array_shift($words);
            $lastName  = implode(' ', $words);
        }

        return [
            self::formatFirstName($firstName),
            self::formatLastName($lastName),
        ];
    }

    /**
     * Geeft de volledige naam terug als "Voornaam Achternaam".
     */
    public static function fullName(string $firstName, string $lastName): string
    {
        return trim("{$firstName} {$lastName}");
    }

    // ── Intern ───────────────────────────────────────────────────────────────

    /**
     * Voornaam: elk woord met hoofdletter.
     * "JAN-CHRISTOPHE" → "Jan-Christophe"
     */
    private static function formatFirstName(string $name): string
    {
        $name  = mb_strtolower($name);
        // Behandel koppeltekens ook
        $parts = preg_split('/([-\s])/', $name, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = [];
        foreach ($parts as $part) {
            $result[] = in_array($part, [' ', '-']) ? $part : self::ucFirst($part);
        }
        return implode('', $result);
    }

    /**
     * Achternaam: tussenvoegsels blijven lowercase, rest met hoofdletter.
     * "VAN DER POEL" → "van der Poel"
     * "VINGEGAARD"   → "Vingegaard"
     */
    private static function formatLastName(string $name): string
    {
        $words  = preg_split('/\s+/', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
        $result = [];

        foreach ($words as $word) {
            $result[] = in_array($word, self::PARTICLES)
                ? $word
                : self::ucFirst($word);
        }

        return implode(' ', $result);
    }

    /**
     * Multibyte-veilige ucfirst.
     */
    private static function ucFirst(string $word): string
    {
        if ($word === '') return '';
        return mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1);
    }
}
