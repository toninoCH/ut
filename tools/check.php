<?php
/**
 * Vor-Release-Prüfung für GEO.Convado. Ohne PrestaShop-Quellen lauffähig.
 *
 * Aufruf (im Repo-Hauptordner):  php tools/check.php [modulordner]
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens ein Fehler.
 *
 * Sie fängt die Befunde ab, die der PrestaShop-Validator bisher erst nach dem Release gemeldet hat.
 * Sie ersetzt den Validator nicht (der braucht die PrestaShop-Klassen für PHPStan).
 */

declare(strict_types=1);

$m = rtrim($argv[1] ?? 'geoconvado', '/');
if (!is_dir($m)) {
    fwrite(STDERR, "Modulordner nicht gefunden: {$m}\n");
    exit(2);
}
$fail = [];
$ok = static function (string $msg): void {
    echo "OK   {$msg}\n";
};
$bad = static function (string $msg) use (&$fail): void {
    $fail[] = $msg;
    echo "FAIL {$msg}\n";
};

$phpFiles = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($m, FilesystemIterator::SKIP_DOTS)) as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (substr($p, -4) === '.php' && strpos($p, '/vendor/') === false) {
        $phpFiles[] = $p;
    }
}

// 1. PHP-Syntax aller Dateien
$syntax = 0;
foreach ($phpFiles as $p) {
    $out = [];
    exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        $bad("PHP-Syntax: {$p}");
        ++$syntax;
    }
}
if ($syntax === 0) {
    $ok(count($phpFiles) . ' PHP-Dateien ohne Syntaxfehler');
}

// 2. Beschreibung: config.xml und $this->description müssen wörtlich übereinstimmen (Validator: «Requirements»)
$xml = (string) file_get_contents("{$m}/config.xml");
$main = (string) file_get_contents("{$m}/{$m}.php");
preg_match('#<description><!\[CDATA\[(.*?)\]\]></description>#s', $xml, $d1);
preg_match('#\$this->description\s*=\s*(?:\$this->trans\(\s*)?\'((?:[^\'\\\\]|\\\\.)*)\'#s', $main, $d2);
if (!isset($d1[1], $d2[1])) {
    $bad('Beschreibung nicht gefunden (config.xml oder ' . $m . '.php)');
} elseif (stripcslashes($d2[1]) !== $d1[1]) {
    $bad("Beschreibung weicht ab:\n       config.xml : {$d1[1]}\n       {$m}.php : " . stripcslashes($d2[1]));
} else {
    $ok('Beschreibung in config.xml und ' . $m . '.php identisch');
}

// 3. Versionen: config.xml, Hauptdatei und README-Änderungsliste
preg_match('#<version><!\[CDATA\[(.*?)\]\]></version>#', $xml, $v1);
preg_match('#\$this->version\s*=\s*\'([^\']+)\'#', $main, $v2);
$readme = is_file("{$m}/README.md") ? (string) file_get_contents("{$m}/README.md") : '';
preg_match('#^- (\d+\.\d+\.\d+):#m', $readme, $v3);
$versions = [$v1[1] ?? '?', $v2[1] ?? '?', $v3[1] ?? '?'];
if (count(array_unique($versions)) === 1 && $versions[0] !== '?') {
    $ok("Version {$versions[0]} in config.xml, {$m}.php und README");
} else {
    $bad('Versionen uneinheitlich: config.xml=' . $versions[0] . ', ' . $m . '.php=' . $versions[1] . ', README=' . $versions[2]);
}

// 4. Bekannte PHPStan-Befunde: «??» auf Eigenschaften, die nie null sind
$nonNullable = ['php_self', 'mpn'];
$hits = 0;
foreach ($phpFiles as $p) {
    foreach (file($p) ?: [] as $i => $line) {
        foreach ($nonNullable as $prop) {
            if (preg_match('/->' . preg_quote($prop, '/') . '\s*\?\?/', $line)) {
                $bad(sprintf('«?? » auf nicht-nullbarer Eigenschaft ->%s: %s:%d', $prop, $p, $i + 1));
                ++$hits;
            }
        }
    }
}
if ($hits === 0) {
    $ok('Keine «??»-Zugriffe auf bekannte nicht-nullbare Eigenschaften (' . implode(', ', $nonNullable) . ')');
}

// 5. README darf keine Tests versprechen, die nicht im Archiv liegen
if (preg_match_all('#\b(?:bash|php)\s+(tests/[A-Za-z0-9_./-]+)#', $readme, $t)) {
    foreach (array_unique($t[1]) as $path) {
        is_file($path) || is_file("{$m}/{$path}") ? $ok("Test vorhanden: {$path}") : $bad("README nennt {$path}, die Datei fehlt");
    }
}

echo $fail ? "\nERGEBNIS: FAILED (" . count($fail) . " Fehler)\n" : "\nERGEBNIS: PASSED\n";
exit($fail ? 1 : 0);
