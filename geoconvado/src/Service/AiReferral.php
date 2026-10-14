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

if (!defined('_PS_VERSION_') && !defined('GEOCONVADO_TEST')) {
    exit;
}

/** Erkennt Besucher, die aus einem KI-Assistenten kommen (Referrer oder utm_source), ohne personenbezogene Daten. */
final class AiReferral
{
    /** Host (oder Subdomain davon) => Quelle. */
    public const HOSTS = [
        'chatgpt.com' => 'chatgpt', 'chat.openai.com' => 'chatgpt',
        'perplexity.ai' => 'perplexity',
        'gemini.google.com' => 'gemini', 'bard.google.com' => 'gemini',
        'copilot.microsoft.com' => 'copilot',
        'claude.ai' => 'claude',
        'chat.mistral.ai' => 'mistral',
        'chat.deepseek.com' => 'deepseek',
        'you.com' => 'you',
        'meta.ai' => 'meta',
        'grok.com' => 'grok',
    ];
    /** utm_source-Werte, die KI-Assistenten an Links anhängen. */
    public const UTM = [
        'chatgpt.com' => 'chatgpt', 'chatgpt' => 'chatgpt', 'openai' => 'chatgpt',
        'perplexity' => 'perplexity', 'perplexity.ai' => 'perplexity',
        'gemini' => 'gemini', 'copilot' => 'copilot', 'claude' => 'claude', 'claude.ai' => 'claude',
        'mistral' => 'mistral', 'deepseek' => 'deepseek', 'grok' => 'grok', 'meta.ai' => 'meta',
    ];
    /** Gültigkeit einer KI-Herkunft für die Zuordnung einer Bestellung. */
    public const WINDOW_DAYS = 30;

    public static function detect(string $referer, string $utmSource): ?string
    {
        $utm = strtolower(trim($utmSource));
        if ($utm !== '' && isset(self::UTM[$utm])) {
            return self::UTM[$utm];
        }
        $host = strtolower((string) parse_url(trim($referer), PHP_URL_HOST));
        if ($host === '') {
            return null;
        }
        foreach (self::HOSTS as $h => $src) {
            if ($host === $h || str_ends_with($host, '.' . $h)) {
                return $src;
            }
        }

        return null;
    }

    /** Wert für das Shop-Cookie: «quelle|zeit». */
    public static function encode(string $source, int $time): string
    {
        return $source . '|' . $time;
    }

    /** Gültige Quelle aus dem Cookie, sonst null (abgelaufen oder unbekannt). */
    public static function decode(string $value, int $now): ?string
    {
        $parts = explode('|', $value, 2);
        if (count($parts) !== 2 || !in_array($parts[0], array_values(self::HOSTS), true) || !ctype_digit($parts[1])) {
            return null;
        }

        return $now - (int) $parts[1] <= self::WINDOW_DAYS * 86400 ? $parts[0] : null;
    }
}
