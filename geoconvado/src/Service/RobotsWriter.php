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

    public const CFG_SIGNAL = 'GEOCONVADO_CONTENT_SIGNAL';
    /** Wählbare Voreinstellungen für Content Signals (https://contentsignals.org). Leer = keine Angabe. */
    public const SIGNAL_PRESETS = [
        'search=yes, ai-input=yes, ai-train=no' => 'Empfohlen: Suche und KI-Antworten ja, Training nein',
        'search=yes, ai-input=yes, ai-train=yes' => 'Alles erlaubt, auch Training',
        'search=yes, ai-input=no, ai-train=no' => 'Nur klassische Suche',
        '' => 'Keine Angabe',
    ];
    public const SIGNAL_DEFAULT = 'search=yes, ai-input=yes, ai-train=no';

    /** Prüft und ordnet eine Content-Signal-Angabe; ungültig ergibt einen leeren Text. */
    public static function normalizeSignal(string $value): string
    {
        $found = [];
        foreach (explode(',', strtolower($value)) as $pair) {
            $kv = array_map('trim', explode('=', $pair, 2));
            if (count($kv) !== 2 || !in_array($kv[0], ['search', 'ai-input', 'ai-train'], true) || !in_array($kv[1], ['yes', 'no'], true)) {
                return '';
            }
            $found[$kv[0]] = $kv[1];
        }
        $out = [];
        foreach (['search', 'ai-input', 'ai-train'] as $k) {
            if (isset($found[$k])) {
                $out[] = $k . '=' . $found[$k];
            }
        }

        return implode(', ', $out);
    }

    /** Der GEO-Block für robots.txt (auch als Vorschau im Backoffice). */
    public function block(string $baseUrl, ?string $robots = null, ?string $signal = null): string
    {
        $signal = self::normalizeSignal($signal ?? (string) \Configuration::get(self::CFG_SIGNAL));
        $block = [self::START];
        if ($signal !== '') {
            // Gruppen mit gleichem User-agent werden von Crawlern zusammengeführt (RFC 9309): die Shop-Regeln bleiben gültig.
            $block[] = 'User-agent: *';
            $block[] = 'Content-Signal: ' . $signal;
            $block[] = '';
        }
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
                $block = array_merge($block, $this->group($crawlers, $rules, true, $signal));
            }
            if ($userAgents) {
                $block = array_merge($block, $this->group($userAgents, $rules, false, $signal));
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
     * @param string $signal Content-Signal-Angabe (leer = keine Zeile)
     *
     * @return string[]
     */
    private function group(array $agents, array $rules, bool $blockBuy, string $signal = ''): array
    {
        $out = [];
        foreach ($agents as $agent) {
            $out[] = 'User-agent: ' . $agent;
        }
        if ($signal !== '') {
            $out[] = 'Content-Signal: ' . $signal;
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
        // Handgeschriebene Sperren für freigegebene Anbieter entfernen, sonst bleibt der Anbieter ausgesperrt.
        $clean = self::removeConflicts($this->strip($current), BotDetector::robotAgents());
        $block = [$this->block($baseUrl, $clean)];

        $next = rtrim($clean) . "\n\n" . implode("\n", $block) . "\n";
        if ($next === $current) {
            \Configuration::updateValue('GEOCONVADO_ROBOTS_ERR', '');

            return true;
        }
        if ((is_file($file) && !is_writable($file)) || (!is_file($file) && !is_writable(_PS_ROOT_DIR_))) {
            \Configuration::updateValue('GEOCONVADO_ROBOTS_ERR', date('Y-m-d H:i') . ' robots.txt nicht beschreibbar');

            return false;
        }
        self::backup($file, $current);
        $ok = AtomicFile::write($file, $next);
        \Configuration::updateValue('GEOCONVADO_ROBOTS_ERR', $ok ? '' : date('Y-m-d H:i') . ' Schreiben fehlgeschlagen');

        return $ok;
    }

    /**
     * Sicherung vor jeder Änderung: «robots.txt.geoconvado-original» einmalig (Zustand vor dem ersten Eingriff),
     * «robots.txt.geoconvado-bak» jeweils der Stand vor der letzten Änderung. Rückweg: Datei zurückkopieren.
     */
    public static function backup(string $file, string $current): void
    {
        if ($current === '') {
            return;
        }
        $original = $file . '.geoconvado-original';
        if (!is_file($original)) {
            @file_put_contents($original, $current);
        }
        @file_put_contents($file . '.geoconvado-bak', $current);
    }

    public function remove(): void
    {
        $file = _PS_ROOT_DIR_ . '/robots.txt';
        if (is_file($file) && is_writable($file)) {
            $current = (string) file_get_contents($file);
            $clean = rtrim($this->strip($current)) . "\n";
            // Nur schreiben, wenn der GEO-Block tatsächlich vorhanden war (eigene robots.txt bleibt sonst unberührt).
            if ($clean !== $current && strpos($current, self::START) !== false) {
                self::backup($file, $current);
                AtomicFile::write($file, $clean);
            }
        }
    }

    /**
     * Gruppen der robots.txt: [['agents' => [...], 'rules' => [[Direktive, Wert]...], 'pre' => [Kommentar-/Leerzeilen]], ...]
     * Eine neue «User-agent»-Zeile nach Regeln beginnt eine neue Gruppe (auch ohne Leerzeile).
     *
     * @return array{groups: array, free: array}
     */
    private static function parse(string $robots): array
    {
        $groups = [];
        $free = [];
        $cur = null;
        $pre = [];
        foreach (preg_split('/\R/', $robots) ?: [] as $raw) {
            $l = trim((string) preg_replace('/#.*/', '', $raw));
            if ($l === '') {
                $pre[] = $raw;
                continue;
            }
            [$k, $v] = array_pad(array_map('trim', explode(':', $l, 2)), 2, '');
            $k = strtolower($k);
            if ($k === 'user-agent') {
                if ($cur === null || $cur['rules']) {
                    if ($cur !== null) {
                        $groups[] = $cur;
                    }
                    $cur = ['agents' => [], 'rules' => [], 'pre' => $pre, 'lines' => []];
                    $pre = [];
                }
                $cur['agents'][] = $v;
                $cur['lines'][] = $raw;
            } elseif ($k === 'sitemap') {
                $free[] = $raw;
            } elseif ($cur !== null) {
                // Kommentare mitten in einer Gruppe bleiben bei ihr.
                $cur['lines'] = array_merge($cur['lines'], $pre);
                $pre = [];
                $cur['rules'][] = [$k, $v];
                $cur['lines'][] = $raw;
            }
        }
        if ($cur !== null) {
            $groups[] = $cur;
        }

        return ['groups' => $groups, 'free' => $free];
    }

    private static function ruleMatches(string $rule, string $path): bool
    {
        if ($rule === '') {
            return false;
        }
        $re = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($rule, '#')) . '#';

        return (bool) preg_match($re, $path);
    }

    /** Darf der Crawler $agent den Pfad laut robots.txt abrufen? (RFC 9309: eigene Gruppe vor «*», längste Regel gewinnt, Allow gewinnt bei Gleichstand.) */
    public static function canFetch(string $robots, string $agent, string $path = '/'): bool
    {
        $rules = [];
        $own = false;
        $star = [];
        foreach (self::parse($robots)['groups'] as $g) {
            $names = array_map('strtolower', $g['agents']);
            if (in_array(strtolower($agent), $names, true)) {
                $own = true;
                $rules = array_merge($rules, $g['rules']);
            } elseif (in_array('*', $names, true)) {
                $star = array_merge($star, $g['rules']);
            }
        }
        $rules = $own ? $rules : $star;
        $best = -1;
        $allow = true;
        foreach ($rules as [$k, $v]) {
            if (($k === 'allow' || $k === 'disallow') && self::ruleMatches($v, $path)) {
                $len = strlen($v);
                if ($len > $best || ($len === $best && $k === 'allow')) {
                    $best = $len;
                    $allow = $k === 'allow';
                }
            }
        }

        return $allow;
    }

    /**
     * Entfernt handgeschriebene Sperren («Disallow: /») für Crawler, die im Modul freigegeben sind. Alle anderen Zeilen,
     * Kommentare und die Sitemap bleiben erhalten.
     *
     * @param string[] $allowedAgents
     */
    public static function removeConflicts(string $robots, array $allowedAgents): string
    {
        $allowed = array_map('strtolower', $allowedAgents);
        $parsed = self::parse($robots);
        $out = [];
        foreach ($parsed['groups'] as $g) {
            $blocksRoot = (bool) array_filter($g['rules'], static fn (array $r): bool => $r[0] === 'disallow' && $r[1] === '/');
            $hit = $blocksRoot ? array_filter($g['agents'], static fn (string $a): bool => in_array(strtolower($a), $allowed, true)) : [];
            if (!$hit) {
                $out = array_merge($out, $g['pre'], $g['lines']);
                $out[] = '';
                continue;
            }
            $rest = array_values(array_filter($g['agents'], static fn (string $a): bool => !in_array(strtolower($a), $allowed, true)));
            if ($rest) {
                $out = array_merge($out, $g['pre']);
                foreach ($rest as $a) {
                    $out[] = 'User-agent: ' . $a;
                }
                foreach ($g['rules'] as [$k, $v]) {
                    $out[] = ucfirst($k) . ': ' . $v;
                }
                $out[] = '';
            }
        }
        $out = array_merge($out, $parsed['free']);

        return rtrim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $out))) . "\n";
    }

    /**
     * Zustand der robots.txt für das Backoffice.
     *
     * @return array{ok: bool, problems: string[]}
     */
    public function status(): array
    {
        $file = _PS_ROOT_DIR_ . '/robots.txt';
        if (!(int) \Configuration::get('GEOCONVADO_ROBOTS')) {
            return ['ok' => true, 'problems' => []];
        }
        $p = [];
        if (!is_file($file)) {
            $p[] = 'robots.txt existiert nicht.';
        } else {
            $content = (string) file_get_contents($file);
            if (!is_writable($file)) {
                $owner = function_exists('posix_getpwuid') ? (posix_getpwuid((int) fileowner($file))['name'] ?? '?') : '?';
                $p[] = 'robots.txt ist für den Webserver nicht beschreibbar (Besitzer ' . $owner . '). Das Modul kann seinen Block nicht pflegen.';
            }
            if (!str_contains($content, self::START)) {
                $p[] = 'Der GEO.Convado-Block fehlt in der robots.txt.';
            }
            foreach (BotDetector::robotAgents() as $a) {
                if (!self::canFetch($content, $a)) {
                    $p[] = $a . ' ist in der robots.txt gesperrt, obwohl der Anbieter im Modul freigegeben ist.';
                }
            }
            $signal = self::normalizeSignal((string) \Configuration::get(self::CFG_SIGNAL));
            if ($signal !== '' && !str_contains($content, 'Content-Signal: ' . $signal)) {
                $p[] = 'Das Content-Signal fehlt in der robots.txt.';
            }
        }
        if ((string) \Configuration::get('GEOCONVADO_ROBOTS_ERR') !== '') {
            $p[] = 'Letzter Schreibversuch: ' . (string) \Configuration::get('GEOCONVADO_ROBOTS_ERR');
        }

        return ['ok' => !$p, 'problems' => $p];
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
