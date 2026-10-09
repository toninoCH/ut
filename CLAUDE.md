# CLAUDE.md – studioCTRL Server / GEO.Convado (Übergabe aus Claude-Chat, 2026-10-09)

## Benutzer
Tonino Di Antonio (CEO Convado AG, Urban Trading GmbH / studioCTRL.ch).
Stil: direkt, kein Blabla, Schweizer Rechtschreibung (ss, nie ß). Zuerst Summary (2–5 Sätze + max. 6 Bullets).
Nur produktionsreife, getestete Arbeit. Nicht ankündigen, sondern machen und Ergebnis zeigen.

## Arbeitsweise in dieser SSH-Session
- Claude läuft direkt auf dem Hostinger-Server (u928734560@de-fra-web1972). Befehle führst du selbst aus.
- Vor jedem Befehl EXPECTED/TARGET/MODE nennen. LIVE-WRITE nur nach ausdrücklichem OK von Tonino.
- Server ist geteilt und stark ausgelastet (Load 30–50): keine parallelen Schwerlast-Jobs, Timeouts setzen.
- OSC52 ist hier nicht nötig (du liest die Ausgabe selbst). Reports trotzdem unter ~/geoconvado_check/ ablegen.

URBAN TRADING / STUDIOCTRL SERVERREGELN

1. GOVERNANCE

Vor JEDER Serverarbeit zuerst lesen, in dieser Reihenfolge:

/home/u928734560/rollback/ROLLBACK_POLICY.md
/home/u928734560/rollback/ROLLBACK_INDEX.tsv
/home/u928734560/rollback/CHANGELOG.tsv

Falls vorhanden und für die Aufgabe relevant zusätzlich:

/home/u928734560/rollback/CSS_POLICY.md
/home/u928734560/rollback/CUSTOM_CODE_POLICY.md
/home/u928734560/rollback/OSC52_POLICY.md

Für ErrorLogs und Healthcheck IMMER:

/home/u928734560/rollback/bin/healthcheck.sh

Jede relevante Änderung benötigt:
Rollback
Änderung
Validierung
Healthcheck
ROLLBACK_INDEX Eintrag
CHANGELOG Eintrag
OSC52 Resultat


2. FOLGEAKTIONEN

Wenn aus einem Ergebnis eindeutig eine notwendige Folgeaktion entsteht,
liefere im selben Antwortfluss DIREKT den nächsten notwendigen Schritt
beziehungsweise Bash.

NICHT auf "Weiter", "Los", "Mach" oder erneute Freigabe warten.

Nur nachfragen wenn:
eine echte Entscheidung notwendig ist
kritische Informationen fehlen
eine irreversible oder riskante Aktion explizite Freigabe benötigt


3. SERVERBASH FORMAT

Bei JEDEM Serverbash zwingend angeben:

EXPECTED=<realistische Laufzeit>
TARGET=<STAGE|LIVE|SERVER>
MODE=<READ_ONLY|WRITE>

Bashs kurz, robust und terminalfest halten.

VERBOTEN:
exit
logout
exec >(...)
Process Substitution
/dev/fd/*
unnötige Subshell Konstruktionen
riskante verschachtelte Heredocs
unnötig komplexe Quote Konstruktionen

Ein Bash darf die SSH Session NIEMALS absichtlich beenden.

Bei Fehler:
STATUS ausgeben
falls erforderlich Rollback durchführen
normal zum Shell Prompt zurückkehren

Keine Monsterbashs.
Komplexe Änderungen in wenige klar abgegrenzte Schritte teilen.
Keine unnötigen Audit Schleifen.


4. OSC52 VERBINDLICH

OSC52 dient AUSSCHLIESSLICH dazu, das kompakte RESULTAT eines
ausgeführten Serverbefehls in die Zwischenablage zu übertragen.

OSC52 DARF NIEMALS enthalten:
Bash Quellcode
auszuführende Befehle
Terminalhistorie
komplette Logs
komplette Healthchecks
CSS
HTML
JavaScript
SQL
JSON Dumps
Dateiinhalte
Debug Dumps

Vollständige technische Informationen gehören in einen REPORT auf
dem Server.

OSC52 Payload MUSS kleiner als 8 KB sein.
Zielgrösse möglichst unter 2 KB.

OSC52 wird ERST erzeugt, nachdem alle Befehle abgeschlossen sind.

Für OSC52 wird ausschliesslich eine vorher vollständig erzeugte,
kleine RESULT Datei beziehungsweise RESULT Variable verwendet.

Vor OSC52 muss geprüft werden, dass der Payload keinen Bash Quellcode
oder unerwartet grosse Inhalte enthält.

Standardformat:

TASK=<task>
STATUS=<SUCCESS|FAILED|ROLLED_BACK|BLOCKED>
TARGET=<STAGE|LIVE|SERVER>
CHANGED=<kurze Zusammenfassung>
VALIDATION=<PASS|FAIL|NOT_RUN>
HEALTHCHECK=<PASS|FAIL|NOT_RUN>
ERROR=<NONE|kurze Ursache>
ROLLBACK=<Pfad|NONE>
REPORT=<Pfad>
LIVE=<UNTOUCHED|CHANGED>

Nur unmittelbar entscheidungsrelevante Zusatzwerte ergänzen.

Nach erfolgreichem OSC52 Copy:

OSC52=RESULT_COPIED
OSC52_BYTES=<Anzahl>

Wenn OSC52 nicht sicher erzeugt werden kann:

OSC52=BLOCKED
REPORT=<Pfad>

NIEMALS versuchen, stattdessen Bash Quellcode oder den vollständigen
Report zu kopieren.

Wenn der Benutzer anschliessend einen TASK/STATUS/CHANGED Block in
ChatGPT einfügt, ist dieser Block als RESULTAT des zuvor ausgeführten
Bash zu behandeln.

NIEMALS behaupten, der Benutzer habe damit den Bash erneut eingefügt.


5. STAGE / LIVE TRENNUNG

STAGE ROOT:
/home/u928734560/domains/stage.studioctrl.ch/public_html

LIVE ROOT:
/home/u928734560/domains/studioctrl.ch/public_html

Datenbankzugang IMMER aus:

TARGET_ROOT/app/config/parameters.php

TARGET_ROOT bestimmt:
PrestaShop Installation
Datenbank
DB Prefix
Konfiguration
Cache
Templates

NIEMALS Datenbanknamen oder Credentials zwischen Stage und LIVE
übernehmen oder hart codieren.

Stage und LIVE benötigen getrennte Rollbacks.

Vor jedem WRITE muss TARGET eindeutig feststehen.

LIVE niemals verändern, wenn TARGET nicht ausdrücklich LIVE ist.


6. CSS SOURCE OF TRUTH

studioCTRL Custom CSS Source of Truth:

SunnyToo Theme Editor
Custom codes
Custom CSS Code

DB Config Key:

STSN_CUSTOM_CSS

Dieser Key existiert getrennt je Environment.

STAGE STSN_CUSTOM_CSS und LIVE STSN_CUSTOM_CSS sind zwei getrennte
Konfigurationswerte.

Normale Custom CSS Änderungen gehören in STSN_CUSTOM_CSS.

Keine zusätzlichen studioctrl-brands-vXX.css Dateien,
Inline CSS Blöcke oder Template Overrides erzeugen, wenn die Änderung
sauber über STSN_CUSTOM_CSS umgesetzt werden kann.

Bestehende CSS Regeln bevorzugt konsolidieren und sauber kommentieren,
statt konkurrierende Overrides aufzubauen.

Vor CSS WRITE:
aktuellen Wert sichern
Candidate validieren
TARGET prüfen
DB aus TARGET_ROOT auflösen
schreiben
Readback durchführen
SHA256 beziehungsweise Inhalt validieren
Frontend validieren
Healthcheck
Changelog
Rollback dokumentieren


7. JAVASCRIPT / TRACKING

CUSTOM_CODE_POLICY.md ist verbindlich.

Keine Datenbank Keys für JavaScript, Tracking, Analytics oder Pixels
erraten.

Vor erster Änderung immer tatsächliche Source of Truth feststellen:
SunnyToo Feld
Modul
GTM
Template
oder andere bestehende Integration.

Keine parallelen Implementierungen erzeugen.


8. ANTWORTVERHALTEN BEI SERVERARBEIT

Zuerst 4 bis 6 sehr kurze Status Bulletpoints.

Danach direkt der notwendige Bash oder konkrete nächste Schritt.

Keine langen Wiederholungen bereits geklärter Grundlagen.

Nach einem ausgeführten Resultat:
Resultat bewerten
notwendige Folgeaktion bestimmen
bei eindeutiger Folgeaktion DIREKT nächsten Bash liefern

Nicht fragen:
"Soll ich weitermachen?"
"Soll ich den nächsten Bash erstellen?"
"Wenn du willst..."

Ziel ist Abschluss mit möglichst wenigen sicheren Schritten.

## Projektstand GEO.Convado (PrestaShop-Modul, Convado AG)
- Aktuelle Version: 1.7.3, ZIP liegt hier: ~/geoconvado_check/geoconvado-1.7.3.zip
  - Validator PrestaShop: alles grün (1.7.2), 1.7.3 = PHP-8.5-Fix (curl_close/imagedestroy entfernt).
  - Inhalt: llms.txt-Generator, KI-Zusammenfassung links + echte Käuferbewertungen rechts,
    Bewertungen mit Foto/Video, CHF-10-Gutschein nach Freigabe (gekennzeichnet), Anti-Spam, DSGVO-Export/-Löschung.
  - Bewertungsprogramm ist standardmässig AUS (GEOCONVADO_RV_ON=0), Video AUS (RV_VIDEO_ON=0), JSON-LD Sterne AUS (RV_LD=0).
- Server (Server-Bench 09.10.): PS 9.1.5, Theme panda (SunnyToo), PHP 8.5.4 (CLI = Web vermutet),
  Limits gross (Upload 1024M, Memory 1024M), GD/WebP/EXIF/fileinfo/proc_open ok, x86_64, Home ausführbar, crontab ok.
  Kein xz, kein python3 auf dem Server.
- STAGE: GEO.Convado NICHT installiert. productcomments v9 aktiv mit 0 Einträgen (8 Hooks) → deaktivieren.
  stproductcomments vorhanden aber inaktiv. afgooglereviews aktiv (Google-Sterne). Mail-Methode: PHP mail().
  STAGE-Produktseite lieferte 503 (Ursache offen: Wartung/Schutz?), Startseite 301.
  AggregateRating kommt evtl. aus stthemeeditor / creativeelements / afgooglereviews → RV_LD erst nach Rich Results Test.
- LIVE: APCu-Erweiterung + PrestaShop «Cache verwenden = CacheApc» von Tonino aktiviert (09.10.). Auf STAGE bewusst AUS
  (gleicher Account → Risiko geteilter APCu-Schlüssel). Wirkung noch nicht gemessen.

## Offene Schritte (in dieser Reihenfolge)
1. ffmpeg installieren: `bash ~/geoconvado_check/gff.sh` (TARGET=SERVER, WRITE nur ~/bin, SHA256 fest geprüft,
   Rollback/Healthcheck/ROLLBACK_INDEX/CHANGELOG eingebaut). Erwartet ~30–90 s.
2. TTFB-Vergleich STAGE vs LIVE bzw. LIVE Cache an/aus (READ_ONLY). STAGE-503 vorher klären.
3. GEO.Convado 1.7.3 auf STAGE installieren (WRITE, mit Rollback):
   productcomments deaktivieren, Modul installieren, Cron einrichten (Cron-URL mit Token aus Modul-Konfiguration,
   alle 10 Min), GEOCONVADO_RV_FFMPEG=/home/u928734560/bin/ffmpeg, GEOCONVADO_RV_FFPROBE=/home/u928734560/bin/ffprobe,
   Bewertungen + Video einschalten, Testbestellung «versendet» → Testbewertung → Freigabe → Gutschein prüfen.
4. Danach erst LIVE (separater Rollback, ausdrückliches OK).

## Ungeprüft in echtem PS 9 (auf STAGE verifizieren)
isGranted-Check im Admin-Controller (fail-closed), Modul-Routen, product_sale.quantity, Bestellstatus-Flags,
Smarty escape_html, Gutschein-Erzeugung (CartRule + Mail), Frontend-Controller review/media,
Full-Page-Cache/Cloudflare: eingeloggte Kunden dürfen kein gecachtes HTML bekommen.
