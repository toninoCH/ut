# GEO.Convado für PrestaShop 9

**Macht Ihren Katalog für ChatGPT, Claude, Perplexity, Gemini und KI-Agenten lesbar und misst, was daraus an Besuchern, Bestellungen und Umsatz entsteht.**

Ob ein KI-Assistent Ihren Shop empfiehlt, entscheidet der Anbieter. Das Modul liefert saubere, aktuelle Daten und einen kontrollierten Kaufweg, und es macht die Wirkung messbar (Reiter «Analyse», «KI-Umsatz»).

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
- 1.13.4: FAQ-Seite: Das Modul merkt sich jetzt den Grund eines fehlgeschlagenen Abrufs beim AIO (Backoffice, «Letzter Abruf beim AIO») und pausiert 5 Minuten, auch wenn noch nie ein Fragment geholt wurde (bisher Neuversuch bei jedem Seitenaufruf).
- 1.13.3: Backoffice: Die Schaltfläche «Bewertungen prüfen» (ein Link) war nur beim Überfahren lesbar, weil das Admin-Theme die Linkfarbe über die Schaltflächenfarbe legte. Alle Link-Schaltflächen des Moduls haben jetzt eigene Farbregeln.
- 1.13.2: Das Front-Stylesheet (Google-Badge, Bewertungsleiste, KI-Einschätzung) wird zusätzlich im Hook actionFrontControllerSetMedia angemeldet, wie PrestaShop es vorsieht. Bisher geschah das nur in displayHeader; bei manchen Themes erschien das Badge dann ohne Gestaltung (alle Zitate untereinander, keine Karte).
- 1.13.1: «Erreichbarkeit testen»: Blockiert Cloudflare den Test vom eigenen Server (HTTP 403), wird robots.txt, llms.txt, das Entdeckungsdokument und /mcp direkt am Server (ohne Cloudflare, per SERVER_ADDR oder 127.0.0.1) geprüft. Zeilen, bei denen das Modul direkt korrekt antwortet, erscheinen als ⚠ (Test über Cloudflare nicht aussagekräftig) statt als ✕; bei den Anbieter-Zeilen steht «Unklar» statt eines Fehlers. Ein ✕ bleibt für echte Fehler.
- 1.13.0: Beratung: `search_products` filtert nach Produktmerkmalen (`features`, z. B. Layout «CH», Switch «Brown»; Wörter ohne Gross-/Kleinschreibung, «CH» trifft nicht «Cherry»), `get_shop_info` nennt unter `searchable_features` die häufigsten Merkmale und Werte, damit ein Agent gültige Filter kennt. Last: der Cron räumt abgelaufene Zwischenspeicher-Einträge auf (die Datenbankvariante tat das bisher zufällig bei Anfragen, jetzt nur noch selten), der Test im Backoffice zeigt, ob APCu oder die Datenbank genutzt wird. Selbsttest: prüft Merkmal-Suche und die Antwortzeit (P95). Neu im Repository: Unit-Tests (`tests/geoconvado/run.php`) in der Vor-Release-Prüfung. Upgrade-Skripte für 1.12.1 und 1.13.0 (keine Datenbankänderung).
- 1.12.1: PrestaShop-Validator: Beschreibung in config.xml an geoconvado.php angeglichen; unnötiges «??» auf ControllerCore::$php_self und ProductCore::$mpn entfernt; Rückgabetypen von AgentCatalog::buyLink() (Schlüssel code) und ConfigController::probe() (Schlüssel type) ergänzt. Keine funktionalen Änderungen. README nennt nur noch Prüfungen, die im Repository liegen (tools/check.php).
- 1.12.0: MCP nach Spezifikation: Origin-Prüfung (Schutz vor DNS-Rebinding, CORS nur für eigene und eingetragene Herkünfte), Prüfung von MCP-Protocol-Version, Content-Type und Accept, Batches nur bis Protokoll 2025-03-26 und mit klarer Fehlermeldung statt stillem Abschneiden, GET/DELETE 405. Varianten durchgehend: Preisspanne nur über bestellbare Varianten, Lager und Bestellbarkeit je Variante, Kaufen-Link nur für genau eine gültige Variante (Pflichtangabe bei Varianten, Mindestmenge, Lager), nie eine Ersatzvariante; die Bestätigungsseite zeigt die Variante sichtbar zur Auswahl. Suche: Artikelnummer, EAN und MPN exakt (auch Varianten), grössere Kandidatenmenge, Filter Marke, Preis, ab Lager, Rückfall auf Namens-/Markensuche. Lastschutz: Ratenbegrenzung je Client und gesamt (HTTP 429, Retry-After), Zwischenspeicher (APCu oder Datenbank), Messung je Werkzeug (Aufrufe, Fehler, Laufzeit). KI-Umsatz: Besucher aus KI-Assistenten (Referrer, utm_source) und Agenten-Kaufen-Links bis zur bezahlten Bestellung, Umsatz und Konversion je Quelle, ohne Personendaten (abschaltbar). Tracker verdichtet lückenlos auch nach Cron-Ausfällen. OpenAPI mit Schema je Werkzeug, AIO-Adresse einstellbar, robots.txt-Sicherung vor jeder Änderung (`robots.txt.geoconvado-original`, `.geoconvado-bak`), Selbsttest im echten Shop (`cli/selftest.php`).
- 1.11.1: PrestaShop-Validator wieder grün: Lizenzkopf in `views/js/webmcp.js`, Reihenfolge der `use`-Anweisungen (ConfigController, geoconvado.php), importierte Klasse statt voll qualifiziertem Namen im Upgrade-Skript. Keine funktionalen Änderungen gegenüber 1.11.0.
- 1.11.0: Vereint die KI-Zugangs-Funktionen mit dem Stand 1.10.5. Content Signals in der robots.txt (Voreinstellung: Suche ja, KI-Eingabe ja, KI-Training nein; Signal auch in den KI-Gruppen), «Markdown for Agents» (`Accept: text/markdown`), FAQ auf der gewählten CMS-Seite (Inhalt und Design aus AIO, FAQPage-JSON-LD, DE/FR/EN/IT, Rückfall auf den bisherigen Inhalt), robots.txt-Prüfung mit sichtbarer Warnung (nicht beschreibbar, Konflikte) und Entfernen widersprüchlicher `Disallow: /` für freigegebene Anbieter, Google-Selbsttest ohne Fehlalarm, strukturierte Versandangaben (Gratis ab, Gebühr, Bestellschluss, Lieferzeit), klare Katalogzählung, FAQ in llms.txt bis 2000 Zeichen. Agenten-Zugang (Schalter, standardmässig an), nur lesend: MCP `/mcp` (`search_products`, `get_product`, `get_buy_link`, `get_shop_info`), Server-Card, API-Katalog, `/openapi.json`, Skills-Index mit SHA-256, `Link`-Header, WebMCP. Kein Checkout. Der Webserver muss `/.well-known/…` an PrestaShop weitergeben.
- 1.10.5: Einkaufsinfos (Zahlung, Lieferzone, Versandkosten, MwSt.): Tabulatoren und Zeilenumbrüche aus eingefügten Texten werden beim Speichern und beim Erzeugen zu einem Leerzeichen. Bisher standen sie als Tabulator in llms.json und llms.txt, eingefügte Tabellen wurden unlesbar.
- 1.10.4: robots.txt: Kunden-Agenten («-User»: ChatGPT-User, Claude-User, Perplexity-User) dürfen die Kauf-Bestätigungsseite öffnen, damit der «Direkt kaufen»-Link der llms.txt funktioniert; Crawler bleiben davon ausgeschlossen. Die Sperre gilt jetzt auch mit Sprachpräfix (/de/module/geoconvado/buy), bisher traf sie nur die Adresse ohne Präfix.
- 1.10.3: Cron-Reiter zeigt fertige Crontab-Zeilen (Kommandozeile empfohlen, curl als Alternative) statt nur der Adressen. Speichern des Schalters «robots.txt automatisch pflegen» schreibt den GEO-Block sofort (bisher erst nach dem nächsten vollständigen Lauf). CMS-Seiten in der llms.txt: Absätze und Zeilenumbrüche werden vor dem Entfernen der Tags durch Leerzeichen ersetzt (Impressum: «Glattalstrasse 63 8052» statt «638052»).
- 1.10.2: robots.txt: Der GEO-Block erlaubt jetzt auch `/llms/` (Kategorie- und Unterkategorie-Dateien); bisher waren sie für Bots mit eigener `Disallow: /`-Gruppe gesperrt. Beim Ausschalten wird die robots.txt nur noch geschrieben, wenn der GEO-Block vorhanden war.
- 1.10.1: Coding-Standard (phpdoc_align in Generator.php), keine funktionalen Änderungen.
- 1.10.0: Kategorie- und Unterkategorie-Dateien über dem Limit (`GEOCONVADO_SPLIT_KB`, Standard 150 KB) werden an Produktgrenzen in Teile `…-teil-N.txt` zerlegt; die ursprüngliche Adresse bleibt als Übersicht mit Links erhalten, der Sprach-Index verlinkt die Teile.
- 1.9.0: Google-Zitate unter dem Preis stammen zuerst aus den lokal synchronisierten Rezensionen von «AF Google Reviews» (Tabelle `af_googlereviews`, nur aktiv, 5 Sterne, mind. 40 Zeichen; Seitensprache zuerst, dann «Default»), Rückfall auf Convado AIO. Schnitt und Anzahl bleiben von Convado AIO.
- 1.8.1: Coding-Standard (single_quote in Generator.php), keine funktionalen Änderungen.
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

## Tests und Release-Gate
- Im echten Shop (STAGE vor LIVE): `php modules/geoconvado/cli/selftest.php --url=https://stage.example.ch` prüft Installation, Tabellen, Hooks, Upgrade-Skripte, Varianten und Preise gegen PrestaShop, Suche, Kaufen-Link-Regeln, MCP über HTTP, Entdeckungsdokumente, Markdown und robots.txt. Exit-Code 0 = bestanden.
- Im Repository: `php tools/check.php geoconvado` (PHP-Syntax, Beschreibung in config.xml gegen Hauptdatei, Versionen, bekannte PHPStan-Muster, Unit-Tests aus `php tests/geoconvado/run.php`). Dieselbe Prüfung läuft in GitHub Actions bei jedem Push. SQL-Tests gegen eine Datenbank liegen nicht in diesem Repository; der Validator des PrestaShop Addons bleibt die massgebende Prüfung.
- Antwortzeit des Agenten-Zugangs: `selftest.php` misst 15 Suchen im Prozess (P95, Grenze 1500 ms, mit `--max-ms=…` änderbar).

## Hooks
`displayHeader` (CSS), `displayProductAdditionalInfo` (Google-Badge), `displayFooterProduct` (KI-Einschätzung), `displayFooterBefore` (Bewertungsleiste), `actionFrontControllerSetMedia` (KI-Besuche zählen), `actionOutputHTMLBefore` (Markdown-Aushandlung), `filterCmsContent` (FAQ auf CMS-Seite), `actionCartSave` und `actionValidateOrder` (KI-Umsatzzuordnung), `actionDeleteGDPRCustomer`/`actionExportGDPRData`, Produkt- und Lager-Hooks (Aktualisierung).

Kompatibel: PrestaShop 9.0+, PHP 8.1+. © Convado
