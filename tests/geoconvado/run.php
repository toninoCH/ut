<?php
/**
 * Unit-Tests ohne PrestaShop (nur reine Regeln). Aufruf im Repo-Hauptordner:  php tests/geoconvado/run.php
 * Exit-Code 0 = bestanden. Läuft auch aus tools/check.php.
 */

declare(strict_types=1);

define('GEOCONVADO_TEST', true);
require __DIR__ . '/../../geoconvado/src/Service/OfferRules.php';

use GeoConvado\Service\OfferRules;

$fail = 0;
$assert = static function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        ++$fail;
    }
};

// Merkmal-Filter
$kb = [['name' => 'Language layout', 'value' => 'CH QWERTZ'], ['name' => 'Switches', 'value' => 'Gateron G Pro Brown'], ['name' => 'Signalling', 'value' => 'Wireless/Wired']];
$assert('Merkmal: Name klein, Wert ganzes Merkmal', OfferRules::matchFeatures($kb, ['language layout' => 'ch qwertz']));
$assert('Merkmal: nur Teil des Namens', OfferRules::matchFeatures($kb, ['layout' => 'CH']));
$assert('Merkmal: ein Wort des Werts', OfferRules::matchFeatures($kb, ['Switches' => 'Brown']));
$assert('Merkmal: zwei Merkmale gleichzeitig', OfferRules::matchFeatures($kb, ['Language layout' => 'CH', 'Switches' => 'Gateron']));
$assert('Merkmal: falscher Wert wird abgelehnt', !OfferRules::matchFeatures($kb, ['Language layout' => 'US']));
$assert('Merkmal: «CH» trifft nicht «Cherry»', !OfferRules::matchFeatures([['name' => 'Switches', 'value' => 'Cherry MX Red']], ['Switches' => 'CH']));
$assert('Merkmal: «CH» trifft «ISO-CH»', OfferRules::matchFeatures([['name' => 'Layout', 'value' => 'ISO-CH']], ['Layout' => 'CH']));
$assert('Merkmal: unbekanntes Merkmal wird abgelehnt', !OfferRules::matchFeatures($kb, ['Farbe' => 'Schwarz']));
$assert('Merkmal: ein falsches von zwei Merkmalen genügt zur Ablehnung', !OfferRules::matchFeatures($kb, ['Language layout' => 'CH', 'Switches' => 'Red']));
$assert('Merkmal: leerer Wert wird abgelehnt', !OfferRules::matchFeatures($kb, ['Switches' => '  ']));
$assert('Merkmal: ohne Filter immer wahr', OfferRules::matchFeatures($kb, []));
$assert('Merkmal: Produkt ohne Merkmale wird abgelehnt', !OfferRules::matchFeatures([], ['Switches' => 'Brown']));
$assert('Merkmal: Umlaute und Gross-/Kleinschreibung', OfferRules::matchFeatures([['name' => 'Geräusch', 'value' => 'Leise']], ['GERÄUSCH' => 'leise']));

// Übrige Filter
$s = ['orderable' => true, 'in_stock' => true, 'brand' => 'Keychron', 'price_min' => 100.0, 'price_max' => 150.0];
$assert('Filter: Marke ohne Gross-/Kleinschreibung', OfferRules::matches($s, ['brand' => 'keychron']));
$assert('Filter: andere Marke', !OfferRules::matches($s, ['brand' => 'Varmilo']));
$assert('Filter: Höchstpreis unter der Preisspanne', !OfferRules::matches($s, ['max_price' => 99.0]));
$assert('Filter: Mindestpreis über der Preisspanne', !OfferRules::matches($s, ['min_price' => 151.0]));
$assert('Filter: nicht bestellbar wird nie gezeigt', !OfferRules::matches(['orderable' => false] + $s, []));
$assert('Filter: nur ab Lager', !OfferRules::matches(['in_stock' => false] + $s, ['in_stock' => true]));
$assert('Code: EAN erkannt', OfferRules::looksLikeCode('7340162401234'));
$assert('Code: Suchsatz mit Leerzeichen ist kein Code', !OfferRules::looksLikeCode('leise mechanische Tastatur'));

echo $fail ? "\nTESTS: FAILED ({$fail})\n" : "\nTESTS: PASSED\n";
exit($fail ? 1 : 0);
