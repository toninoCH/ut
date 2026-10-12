# GEO.Convado für PrestaShop 9

**Damit ChatGPT, Claude, Perplexity und Gemini Ihren Shop kennen, empfehlen und direkt daraus bestellen lassen.**

GEO.Convado stellt Ihren kompletten Katalog in allen aktiven Shop-Sprachen als `llms.txt` bereit – dem Format, das KI-Assistenten bevorzugt lesen. Jedes Produkt enthält Preis, Aktionspreis, Lager mit Schweiz-Vorteil, Merkmale, Varianten, Bewertungen, FAQs, eine neutrale Einschätzung mit Vor- und Nachteilen sowie einen Direktkauf-Link für KI-Agenten. Dazu kommen Kategorien, Marken, Service-Seiten und Ihre Einkaufsbedingungen (Zahlarten, Lieferzone, Versandkosten, MwSt.).

## Was Ihre Kunden sehen
- **Neutrale KI-Einschätzung** auf jeder Produktseite: kurze Zusammenfassung, Vorteile, Nachteile, «Ideal für» und «Weniger geeignet für». Sachlich, nicht verkäuferisch.
- **Google-Bewertungen**: Badge unter dem Preis und eine Bewertungsleiste über dem Footer.

## Was KI-Assistenten sehen
- `/llms.txt`, `/llms-{sprache}.txt`, `/llms-full-{sprache}.txt`, `/llms/{sprache}/{kategorie}.txt`
- Einkaufsfakten (Accepted-Payment, Shipping-Zone, Shipping-Cost, Currency, VAT-Included)
- Direktkauf: `/module/geoconvado/buy?id=…&qty=…&ipa=…` legt in den Warenkorb und öffnet die Kasse. Der Kunde schliesst immer selbst ab.

## Zuverlässigkeit
Statische Dateien (keine Serverlast für Crawler), Aufbau in Blöcken, fortsetzbar, nie doppelt, atomar veröffentlicht. Fällt Convado AIO aus, werden die zuletzt gespeicherten Daten verwendet. Das Frontend liest nur den lokalen Zwischenspeicher – null Wartezeit für Kunden.

## Einrichtung
1. ZIP unter *Module → Modul hochladen* installieren.
2. Verbindungsschlüssel aus Convado AIO (GEO → llms.txt) einfügen, «Speichern & Verbindung testen».
3. Cron-Adresse alle 10 Minuten aufrufen lassen.

## Kundenbewertungen (ab 1.7.0, standardmässig aus)
Nur eingeloggte Käufer mit versendeter, nicht zurückgegebener Bestellung (Wartefrist einstellbar). Schutz: Formular-Token mit Zeitsperre, Honigtopf, Ratenbegrenzung, Link-/Kontaktsperre, Duplikat-Erkennung, Moderation. Optional Foto-/Videoupload (Bilder werden neu kodiert, Metadaten entfernt; Videos per ffmpeg umgewandelt), optional Gutschein nach Freigabe (klar gekennzeichnet «Gegen Gutschein eingereicht»). Ablage ausserhalb des Webroots (`var/geoconvado-media`). Aktivierung: *Konfiguration → Bewertungen*. Bewertungen bleiben bei Deinstallation erhalten, ausser die Löschoption ist gesetzt.

## Änderungen
- 1.8.0: Hauptkategorien über 150 KB (Einstellung `GEOCONVADO_SPLIT_KB`, 0 = nie) werden in eine Datei je Unterkategorie aufgeteilt (`llms/<sprache>/<haupt>--<unter>.txt`); die Hauptkategorie-Datei wird zur Übersicht, der Sprach-Index verlinkt die Unterkategorien. Zuordnung über die Standardkategorie des Produkts.
- 1.7.8: Analyse: neue Tabelle «Letzte Abrufe» (Zeit, KI, Art, Datei bzw. Seite, anonymisierte IP, User-Agent; letzte 40, nur Lesezugriff).
- 1.7.7: Lieferzeit pro Produkt in llms.txt (Text «wenn auf Lager» bzw. «Label when out of stock» samt Verfügbarkeitsdatum). Fehler behoben: Produkte ohne Lager und ohne Nachbestellung («Bestellungen ablehnen») wurden als «Auf Bestellung lieferbar» gemeldet, jetzt «Nicht verfügbar».
- 1.7.6: Google-Zitate unter dem Preis rotieren (bis zu 5 Zitate mit 5 Sternen, alle 6 Sekunden, reines CSS, Pause beim Darüberfahren, ohne Animation bei reduzierter Bewegung).
- 1.7.5: Validator-Konformität: Kontext im CLI-Cron ohne direkten `Context`-Aufruf (`GeoConvado::getShopContext()`), keine funktionalen Änderungen gegenüber 1.7.4.
- 1.7.4: Kurzprofil als erste Zeile jedes Produkts (Merkmale über `GEOCONVADO_PROFILE_FEATURES`, sonst die ersten sechs), GTIN/EAN und MPN, beste Beschreibung statt Wiederholung des Titels, gleichnamige Merkmale in einer Zeile, «Geeignet für» nur bei echten Einsatzzwecken, Dateien in DE/EN/FR vollständig übersetzt, Anleitung korrigiert (Bestätigungsseite), Kategorien mit Elternkategorie und Produktanzahl, Cron per Kommandozeile (`php modules/geoconvado/cli/cron.php`), Fehler `started_at` behoben.
- 1.7.3: PHP 8.5: veraltete Aufrufe (curl_close, imagedestroy) entfernt.
- 1.7.2: Strukturierte Daten (FAQ, Bewertungen) wieder aus dem Template, ohne HTML im PHP-Code und ohne nofilter.
- 1.7.1: PrestaShop-Validator wieder vollständig grün: Escaping nach PS-Standard (ohne nofilter), strukturierte Daten aus PHP, fehlender Import im Upsell-Cache, Coding-Standard.
- 1.7.0: Bewertungssystem inkl. Medien, Gutschein, Moderation, Datenschutz-Export/-Löschung; Bewertungen in llms.txt.
- 1.6.1: Änderungen gehen beim Drosseln nicht mehr verloren; Berechtigungsprüfung im Backend; Einkaufsangaben (Zahlung/Versand/MwSt.) zur Prüfung markiert; FAQ-JSON-LD korrekt ausgegeben.

## Hooks
`displayHeader` (CSS), `displayProductAdditionalInfo` (Google-Badge), `displayFooterProduct` (KI-Einschätzung), `displayFooterBefore` (Bewertungsleiste), `actionFrontControllerSetMedia` (KI-Besuche zählen), `actionDeleteGDPRCustomer`/`actionExportGDPRData`, Produkt- und Lager-Hooks (Aktualisierung).

Kompatibel: PrestaShop 9.0+, PHP 8.1+. © Convado
