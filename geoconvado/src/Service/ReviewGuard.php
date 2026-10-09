<?php
/**
 * GEO.Convado - AI visibility (llms.txt) for PrestaShop 9
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from Convado AG.
 * Use, copy, modification or distribution is only permitted with a valid
 * license agreement.
 *
 * @author    Convado AG <info@convado.ch>
 * @copyright 2026 Convado AG
 * @license   Commercial license, see LICENSE.md
 */

declare(strict_types=1);

namespace GeoConvado\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Spam- und Missbrauchsschutz für Käuferbewertungen. Reine Funktionen, ohne Datenbank (testbar).
 * Die stärkste Schranke ist die Kaufprüfung (ReviewEligibility); diese Klasse ergänzt sie.
 */
final class ReviewGuard
{
    public const MIN_FORM_AGE = 6;      // Sekunden: schneller kann kein Mensch ein Formular ausfüllen
    public const MAX_FORM_AGE = 7200;   // 2 Stunden

    private static function secret(): string
    {
        return hash('sha256', 'geoconvado-review|' . (defined('_COOKIE_KEY_') ? _COOKIE_KEY_ : 'dev'));
    }

    /** Formular-Token: Zeitstempel + HMAC über Artikel, Kunde und Zeit. */
    public static function formToken(int $idProduct, int $idCustomer, int $ts): string
    {
        return $ts . '.' . hash_hmac('sha256', $idProduct . '|' . $idCustomer . '|' . $ts, self::secret());
    }

    public static function verifyToken(string $token, int $idProduct, int $idCustomer, ?int $now = null): bool
    {
        $now ??= time();
        if (!preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $m)) {
            return false;
        }
        $ts = (int) $m[1];
        $age = $now - $ts;
        if ($age < self::MIN_FORM_AGE || $age > self::MAX_FORM_AGE) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $idProduct . '|' . $idCustomer . '|' . $ts, self::secret()), $m[2]);
    }

    /** IP nur als abgekürzter HMAC (für Ratenbegrenzung, nicht rückrechenbar; wird nach 30 Tagen geleert). */
    public static function ipHash(string $ip): string
    {
        return substr(hash_hmac('sha256', $ip, self::secret()), 0, 32);
    }

    /** Text bereinigen: Tags, Steuerzeichen, übermässige Leerzeilen. */
    public static function clean(string $s): string
    {
        $s = strip_tags($s);
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        $s = str_replace("\r\n", "\n", $s);
        $s = (string) preg_replace("/\n{3,}/", "\n\n", $s);

        return trim($s);
    }

    public static function bodyHash(string $body): string
    {
        $n = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $body));

        return sha1($n);
    }

    /**
     * Prüft Inhalt. Rückgabe: null = in Ordnung, sonst Fehlerschlüssel (links|short|long|spam|blocked).
     *
     * @param string[] $blocklist
     */
    public static function content(string $title, string $body, int $min, int $max, array $blocklist = []): ?string
    {
        $len = mb_strlen($body);
        if ($len < $min) {
            return 'short';
        }
        if ($len > $max) {
            return 'long';
        }
        $all = $title . "\n" . $body;
        if (preg_match('~(https?://|www\.|\b[a-z0-9-]{2,}\.(com|net|org|ch|de|at|io|ru|cn|xyz|top|info|biz|shop|store|online|site|click|link|app|me|tv|cc)\b|[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}|(?<!\d)(\+|00)?\d[\d ./-]{8,}\d(?!\d))~iu', $all)) {
            return 'links';
        }
        if (preg_match('/(.)\1{11,}/su', $all)) {
            return 'spam';
        }
        $letters = preg_match_all('/\p{L}/u', $body);
        if ($letters < $len * 0.5) {
            return 'spam';
        }
        $upper = preg_match_all('/\p{Lu}/u', $body);
        if ($letters >= 40 && $upper / $letters > 0.7) {
            return 'spam';
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($body), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) >= 20 && count(array_unique($words)) / count($words) < 0.3) {
            return 'spam';
        }
        $low = mb_strtolower($all);
        foreach ($blocklist as $w) {
            $w = trim(mb_strtolower($w));
            if ($w !== '' && mb_strpos($low, $w) !== false) {
                return 'blocked';
            }
        }

        return null;
    }

    /** Anzeigename: Vorname und Initial des Nachnamens («Anna K.»). */
    public static function displayName(string $first, string $last): string
    {
        $first = trim(strip_tags($first));
        $last = trim(strip_tags($last));
        $initial = $last !== '' ? mb_strtoupper(mb_substr($last, 0, 1)) . '.' : '';
        $name = trim(mb_substr($first, 0, 30) . ' ' . $initial);

        return $name !== '' ? $name : 'Kunde';
    }

    /** Erlaubte https-Adresse (Blog-Link), sonst leer. */
    public static function httpsUrl(string $u): string
    {
        $u = trim($u);

        return preg_match('#^https://[^\s"<>]{4,240}$#i', $u) === 1 ? $u : '';
    }
}
