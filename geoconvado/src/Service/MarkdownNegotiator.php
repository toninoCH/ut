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

/**
 * «Markdown for Agents»: Wer ausdrücklich «Accept: text/markdown» sendet, bekommt den sichtbaren Seiteninhalt
 * als Markdown statt HTML. Browser und Suchmaschinen (Accept: text/html) sehen unverändert HTML.
 */
final class MarkdownNegotiator
{
    private const MAX_HTML = 3000000;
    /** Nie in den Inhalt übernehmen. */
    private const DROP = ['script', 'style', 'noscript', 'svg', 'iframe', 'template', 'head', 'nav', 'header', 'footer', 'aside',
        'input', 'button', 'select', 'textarea', 'option', 'canvas', 'video', 'audio', 'object', 'embed', 'link', 'meta'];
    private const BLOCK = ['div', 'section', 'article', 'main', 'figure', 'figcaption', 'address', 'details', 'summary', 'fieldset',
        'legend', 'dl', 'dt', 'dd', 'tr', 'li', 'form', 'body', 'html'];

    /** Nur wenn text/markdown ausdrücklich genannt ist und mindestens so hoch gewichtet wird wie HTML. */
    public static function wants(string $accept): bool
    {
        $md = null;
        $html = 0.0;
        foreach (explode(',', strtolower($accept)) as $part) {
            $bits = array_map('trim', explode(';', $part));
            $type = (string) array_shift($bits);
            $q = 1.0;
            foreach ($bits as $b) {
                if (str_starts_with($b, 'q=')) {
                    $q = (float) substr($b, 2);
                }
            }
            if ($type === 'text/markdown') {
                $md = max($md ?? 0.0, $q);
            } elseif ($type === 'text/html' || $type === 'application/xhtml+xml') {
                $html = max($html, $q);
            }
        }

        return $md !== null && $md > 0 && $md >= $html;
    }

    /** Markdown-Fassung der Seite, oder null, wenn sie sich nicht sinnvoll umwandeln lässt. */
    public static function convert(string $html, string $url): ?string
    {
        if ($html === '' || strlen($html) > self::MAX_HTML) {
            return null;
        }
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return null;
        }
        $xp = new \DOMXPath($dom);
        $root = null;
        foreach (['//main', '//*[@id="wrapper"]', '//*[@id="content"]', '//body'] as $q) {
            $found = $xp->query($q);
            if ($found && $found->length > 0) {
                $root = $found->item(0);
                break;
            }
        }
        if (!$root) {
            return null;
        }
        $self = new self($url);
        $md = $self->clean($self->children($root, 0), true);
        if (mb_strlen($md) < 40) {
            return null;
        }
        $title = '';
        $t = $dom->getElementsByTagName('title')->item(0);
        if ($t) {
            $title = trim((string) preg_replace('/\s+/u', ' ', $t->textContent));
        }
        if ($title !== '' && !preg_match('/^#\s/m', $md)) {
            $md = '# ' . $title . "\n\n" . $md;
        }

        return $md . "\n\n---\nQuelle: " . $url . "\n";
    }

    private function __construct(private readonly string $base)
    {
    }

    private function children(\DOMNode $n, int $depth): string
    {
        $out = '';
        foreach ($n->childNodes as $c) {
            $out .= $this->node($c, $depth);
        }

        return $out;
    }

    private function node(\DOMNode $n, int $depth): string
    {
        if ($n instanceof \DOMText) {
            return (string) preg_replace('/\s+/u', ' ', $n->nodeValue ?? '');
        }
        if (!$n instanceof \DOMElement) {
            return '';
        }
        $tag = strtolower($n->tagName);
        if (in_array($tag, self::DROP, true) || $n->hasAttribute('hidden') || strtolower($n->getAttribute('aria-hidden')) === 'true'
            || preg_match('/display\s*:\s*none/i', $n->getAttribute('style'))) {
            return '';
        }
        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            $t = $this->line($this->children($n, $depth));

            return $t === '' ? '' : "\n\n" . str_repeat('#', (int) $m[1]) . ' ' . $t . "\n\n";
        }

        switch ($tag) {
            case 'p':
                $t = trim($this->children($n, $depth));

                return $t === '' ? '' : "\n\n" . $t . "\n\n";
            case 'br':
                return "\n";
            case 'hr':
                return "\n\n---\n\n";
            case 'strong':
            case 'b':
                $t = $this->line($this->children($n, $depth));

                return $t === '' ? '' : '**' . $t . '**';
            case 'em':
            case 'i':
                $t = $this->line($this->children($n, $depth));

                return $t === '' ? '' : '*' . $t . '*';
            case 'code':
                $t = $this->line($n->textContent);

                return $t === '' ? '' : '`' . str_replace('`', "'", $t) . '`';
            case 'pre':
                return "\n\n```\n" . strtr(rtrim($n->textContent), [' ' => "\x02", "\t" => "\x02\x02"]) . "\n```\n\n";
            case 'blockquote':
                $t = trim($this->clean($this->children($n, $depth)));
                if ($t === '') {
                    return '';
                }

                return "\n\n" . implode("\n", array_map(static fn (string $l): string => '> ' . $l, explode("\n", $t))) . "\n\n";
            case 'a':
                return $this->link($n, $depth);
            case 'img':
                $alt = $this->line($n->getAttribute('alt'));
                $src = $this->abs($n->getAttribute('src') ?: $n->getAttribute('data-src'));

                return ($alt === '' || $src === '') ? '' : '![' . $alt . '](' . $src . ')';
            case 'ul':
            case 'ol':
                return "\n" . $this->lists($n, $depth, $tag === 'ol') . "\n";
            case 'table':
                return "\n\n" . $this->table($n) . "\n\n";
        }

        $inner = $this->children($n, $depth);

        return in_array($tag, self::BLOCK, true) ? "\n" . $inner . "\n" : $inner;
    }

    private function link(\DOMElement $n, int $depth): string
    {
        $text = $this->line($this->children($n, $depth));
        $href = trim($n->getAttribute('href'));
        if ($text === '' || $href === '' || $href[0] === '#' || stripos($href, 'javascript:') === 0) {
            return $text;
        }
        $abs = $this->abs($href);

        return $abs === '' ? $text : '[' . $text . '](' . $abs . ')';
    }

    private function lists(\DOMElement $list, int $depth, bool $ordered): string
    {
        $out = '';
        $i = 0;
        foreach ($list->childNodes as $li) {
            if (!$li instanceof \DOMElement || strtolower($li->tagName) !== 'li') {
                continue;
            }
            $content = trim($this->clean($this->children($li, $depth + 1)));
            if ($content === '') {
                continue;
            }
            ++$i;
            $lines = explode("\n", $content);
            $first = (string) array_shift($lines);
            $item = str_repeat("\x01", $depth) . ($ordered ? $i . '. ' : '- ') . ltrim($first, "\x01 ");
            foreach ($lines as $l) {
                $l = rtrim($l);
                if ($l === '') {
                    continue;
                }
                $item .= "\n" . ($l[0] === "\x01" ? $l : str_repeat("\x01", $depth + 1) . ltrim($l));
            }
            $out .= $item . "\n";
        }

        return $out;
    }

    private function table(\DOMElement $t): string
    {
        $rows = [];
        foreach ($t->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $c) {
                if ($c instanceof \DOMElement && in_array(strtolower($c->tagName), ['td', 'th'], true)) {
                    $cells[] = str_replace('|', '/', $this->line($this->children($c, 0)));
                }
            }
            if ($cells && trim(implode('', $cells)) !== '') {
                $rows[] = $cells;
            }
        }
        if (!$rows) {
            return '';
        }
        $cols = max(array_map('count', $rows));
        if ($cols < 2) {
            return implode("\n\n", array_map(static fn (array $r): string => (string) $r[0], $rows));
        }
        $fmt = static fn (array $r): string => '| ' . implode(' | ', array_pad($r, $cols, '')) . ' |';
        $out = [$fmt($rows[0]), '|' . str_repeat(' --- |', $cols)];
        foreach (array_slice($rows, 1) as $r) {
            $out[] = $fmt($r);
        }

        return implode("\n", $out);
    }

    /** Einzeiliger, bereinigter Text. */
    private function line(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\x01", '', $s)));
    }

    private function abs(string $u): string
    {
        $u = trim($u);
        if ($u === '' || stripos($u, 'data:') === 0 || stripos($u, 'javascript:') === 0) {
            return '';
        }
        if (preg_match('#^https?://#i', $u)) {
            return $u;
        }
        $p = parse_url($this->base);
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($u, '//')) {
            return ($p['scheme'] ?? 'https') . ':' . $u;
        }
        if ($u[0] === '/') {
            return $origin . $u;
        }
        if ($u[0] === '?') {
            return $origin . ($p['path'] ?? '/') . $u;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $u)) {
            return $u; // mailto:, tel:
        }
        $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');

        return $origin . $dir . $u;
    }

    /** Leerzeilen und Einrückung vereinheitlichen. */
    private function clean(string $md, bool $final = false): string
    {
        $lines = [];
        foreach (explode("\n", $md) as $l) {
            $l = rtrim((string) preg_replace('/[ \t]+/', ' ', $l));
            $l = (string) preg_replace('/^ +/', '', $l);
            $lines[] = $l;
        }
        $md = (string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));
        $md = trim($md);

        return $final ? strtr($md, ["\x01" => '  ', "\x02" => ' ']) : $md;
    }
}
