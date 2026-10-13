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

final class RobotsWriter
{
    private const START = '# GEO.Convado start';
    private const END = '# GEO.Convado end';

    /** Der GEO-Block für robots.txt (auch als Vorschau im Backoffice). */
    public function block(string $baseUrl, ?string $robots = null): string
    {
        $block = [self::START];
        // Eine eigene Gruppe ersetzt für diesen Bot die «User-agent: *»-Gruppe. Deshalb werden die
        // PrestaShop-Sperren (Warenkorb, Kasse, Suche, Filter, Module) übernommen statt «Allow: /».
        $rules = $this->starRules($robots ?? $this->current());
        $agents = BotDetector::robotAgents();
        if ($agents) {
            // Crawler dürfen die Kauf-Bestätigungsseite nicht abrufen. Agenten, die im Auftrag eines Kunden
            // handeln («-User»), brauchen sie, sonst läuft der «Direkt kaufen»-Link aus der llms.txt ins Leere.
            $userAgents = array_values(array_intersect($agents, BotDetector::USER_AGENTS));
            $crawlers = array_values(array_diff($agents, $userAgents));
            if ($crawlers) {
                $block = array_merge($block, $this->group($crawlers, $rules, true));
            }
            if ($userAgents) {
                $block = array_merge($block, $this->group($userAgents, $rules, false));
            }
        }
        foreach (BotDetector::blockedAgents() as $agent) {
            $block[] = 'User-agent: ' . $agent;
            $block[] = 'Disallow: /';
            $block[] = '';
        }
        $block[] = '# KI-lesbarer Katalog: ' . rtrim($baseUrl, '/') . '/llms.txt';
        $block[] = '# Maschinenlesbar: ' . rtrim($baseUrl, '/') . '/llms.json';
        $block[] = self::END;

        return implode("\n", $block);
    }

    /**
     * Eine robots.txt-Gruppe für KI-Agenten.
     *
     * @param string[] $agents
     * @param string[] $rules Disallow-Zeilen der «User-agent: *»-Gruppe
     *
     * @return string[]
     */
    private function group(array $agents, array $rules, bool $blockBuy): array
    {
        $out = [];
        foreach ($agents as $agent) {
            $out[] = 'User-agent: ' . $agent;
        }
        $out[] = 'Allow: /llms.txt';
        $out[] = 'Allow: /llms.json';
        $out[] = 'Allow: /llms-';
        $out[] = 'Allow: /llms/';
        foreach ($rules as $r) {
            // Eine allgemeine Modul-Sperre würde auch die Kaufseite der Kunden-Agenten schliessen.
            if (!$blockBuy && preg_match('#^Disallow:\s*(/\*)?/module/?$#i', $r)) {
                continue;
            }
            $out[] = $r;
        }
        if ($blockBuy) {
            // Mit und ohne Sprachpräfix: die Adresse lautet /de/module/geoconvado/buy.
            $out[] = 'Disallow: /module/geoconvado/buy';
            $out[] = 'Disallow: /*/module/geoconvado/buy';
        }
        $out[] = '';

        return $out;
    }

    public function ensure(string $baseUrl): bool
    {
        $file = _PS_ROOT_DIR_ . '/robots.txt';
        $current = is_file($file) ? (string) file_get_contents($file) : '';
        $clean = $this->strip($current);
        $block = [$this->block($baseUrl, $clean)];

        $next = rtrim($clean) . "\n\n" . implode("\n", $block) . "\n";
        if ($next === $current) {
            return true;
        }
        if ((is_file($file) && !is_writable($file)) || (!is_file($file) && !is_writable(_PS_ROOT_DIR_))) {
            return false;
        }

        return AtomicFile::write($file, $next);
    }

    public function remove(): void
    {
        $file = _PS_ROOT_DIR_ . '/robots.txt';
        if (is_file($file) && is_writable($file)) {
            $current = (string) file_get_contents($file);
            $clean = rtrim($this->strip($current)) . "\n";
            // Nur schreiben, wenn der GEO-Block tatsächlich vorhanden war (eigene robots.txt bleibt sonst unberührt).
            if ($clean !== $current && strpos($current, self::START) !== false) {
                AtomicFile::write($file, $clean);
            }
        }
    }

    private function current(): string
    {
        $file = _PS_ROOT_DIR_ . '/robots.txt';

        return is_file($file) ? $this->strip((string) file_get_contents($file)) : '';
    }

    /** Disallow-Zeilen der «User-agent: *»-Gruppe. */
    private function starRules(string $robots): array
    {
        $out = [];
        $inStar = false;
        $prevAgent = false;
        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $l = trim((string) preg_replace('/#.*/', '', $line));
            if ($l === '') {
                continue;
            }
            if (stripos($l, 'user-agent:') === 0) {
                $isStar = trim(substr($l, 11)) === '*';
                $inStar = $prevAgent ? ($inStar || $isStar) : $isStar;
                $prevAgent = true;
                continue;
            }
            $prevAgent = false;
            if ($inStar && stripos($l, 'disallow:') === 0) {
                $out[] = $l;
            }
        }
        if (!$out) {
            $out = ['Disallow: /*?order=', 'Disallow: /*?q=', 'Disallow: /*&q=', 'Disallow: /cart', 'Disallow: /order', 'Disallow: /my-account', 'Disallow: /module/'];
        }

        return array_values(array_unique($out));
    }

    private function strip(string $content): string
    {
        return (string) preg_replace('/\n*' . preg_quote(self::START, '/') . '.*?' . preg_quote(self::END, '/') . '\n?/s', "\n", $content);
    }
}
