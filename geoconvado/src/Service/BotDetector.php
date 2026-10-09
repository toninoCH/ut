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

final class BotDetector
{
    /** User-Agent-Fragment => Anzeigename. */
    public const BOTS = [
        'GPTBot' => 'GPTBot',
        'OAI-SearchBot' => 'OAI-SearchBot',
        'ChatGPT-User' => 'ChatGPT-User',
        'ClaudeBot' => 'ClaudeBot',
        'Claude-User' => 'Claude-User',
        'Claude-SearchBot' => 'Claude-SearchBot',
        'Claude-Web' => 'ClaudeBot',
        'anthropic-ai' => 'ClaudeBot',
        'PerplexityBot' => 'PerplexityBot',
        'Perplexity-User' => 'PerplexityBot',
        // Google-Extended / Applebot-Extended sind reine robots.txt-Steuerzeichen ohne eigenen Besucher (nicht zählbar).
        'GoogleOther' => 'GoogleOther',
        'CCBot' => 'CCBot',
        'meta-externalagent' => 'Meta-AI',
        'Bytespider' => 'Bytespider',
        'Amazonbot' => 'Amazonbot',
    ];

    public static function detect(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }
        foreach (self::BOTS as $needle => $name) {
            if (stripos($userAgent, $needle) !== false) {
                return $name;
            }
        }

        return null;
    }

    /** Anbieter => [Anzeigename, robots.txt-Agents]. */
    public const PROVIDERS = [
        'openai' => ['ChatGPT / OpenAI', ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User']],
        'anthropic' => ['Claude / Anthropic', ['ClaudeBot', 'Claude-SearchBot', 'Claude-User', 'anthropic-ai']],
        'perplexity' => ['Perplexity', ['PerplexityBot', 'Perplexity-User']],
        'google' => ['Gemini / Google', ['Google-Extended']],
        'apple' => ['Apple Intelligence', ['Applebot-Extended']],
        'meta' => ['Meta AI', ['meta-externalagent']],
        'amazon' => ['Amazon / Alexa', ['Amazonbot']],
        'commoncrawl' => ['Common Crawl (Trainingsdaten)', ['CCBot']],
    ];

    /** Typische User-Agents für den Selbsttest. */
    public const TEST_UA = [
        'openai' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot',
        'anthropic' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
        'perplexity' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
        'google' => 'Mozilla/5.0 (compatible; Google-Extended)',
    ];

    /**
     * Freigegebene Anbieter laut Einstellung (leer = alle).
     *
     * @return string[]
     */
    public static function allowedProviders(): array
    {
        $raw = (string) \Configuration::get('GEOCONVADO_BOTS');
        // Standard: alle Such-/Antwort-Bots frei, reine Trainingsdaten-Sammler (Common Crawl) gesperrt.
        $list = $raw === '' ? array_diff(array_keys(self::PROVIDERS), ['commoncrawl']) : (array) json_decode($raw, true);

        return array_values(array_intersect(array_keys(self::PROVIDERS), $list));
    }

    /** Eindeutige User-Agents für robots.txt. */
    public static function robotAgents(): array
    {
        $out = [];
        foreach (self::allowedProviders() as $p) {
            $out = array_merge($out, self::PROVIDERS[$p][1]);
        }

        return $out;
    }

    /** User-Agents gesperrter Anbieter. */
    public static function blockedAgents(): array
    {
        $out = [];
        foreach (array_diff(array_keys(self::PROVIDERS), self::allowedProviders()) as $p) {
            $out = array_merge($out, self::PROVIDERS[$p][1]);
        }

        return $out;
    }
}
