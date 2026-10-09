# CLAUDE.md: Urban Trading / studioCTRL Serverarbeit

## 0. Kontext

- Nutzer: Tonino Di Antonio, CEO AInnovators AG (demnächst Convado AG). Fokus: KI-Agenten, SaaS, AInno.Guide.
- Claude läuft per SSH direkt auf dem Hostinger-Server (User `u928734560`). Befehle werden selbst ausgeführt, kein Copy/Paste.
- Arbeitsordner: `/home/u928734560/geoconvado_check`
  - `CLAUDE.md` (diese Datei)
  - `gff.sh` (ffmpeg-Bash)
  - `geoconvado-1.7.3.zip` (Modul)
- Berechtigungsmodus: Manual. Vor jedem Befehl Freigabe einholen. Der Zugang umfasst auch LIVE.

## 1. Stil

- Direkt, kein Blabla, Schweizer Rechtschreibung (kein ß).
- Summary zuerst: 2-5 Sätze plus max. 6 Bullets à 12 Wörter.
- PDCA (PLAN/DO/CHECK/ACT) nur auf Wunsch.
- Bei Serverarbeit zuerst 4-6 sehr kurze Status-Bulletpoints, danach direkt der nächste Schritt.
- Keine Wiederholung geklärter Grundlagen. Nicht fragen «Soll ich weitermachen?». Bei eindeutiger Folgeaktion direkt ausführen.
- Nur nachfragen bei: echter Entscheidung, fehlenden kritischen Informationen, irreversibler oder riskanter Aktion.

## 2. Governance (vor JEDER Serverarbeit)

Zuerst lesen, in dieser Reihenfolge:

1. `/home/u928734560/rollback/ROLLBACK_POLICY.md`
2. `/home/u928734560/rollback/ROLLBACK_INDEX.tsv`
3. `/home/u928734560/rollback/CHANGELOG.tsv`

Falls vorhanden und relevant zusätzlich: `CSS_POLICY.md`, `CUSTOM_CODE_POLICY.md`, `OSC52_POLICY.md` (alle in `/home/u928734560/rollback/`).

Für ErrorLogs und Healthcheck immer: `/home/u928734560/rollback/bin/healthcheck.sh`

Jede relevante Änderung braucht: Rollback, Änderung, Validierung, Healthcheck, ROLLBACK_INDEX-Eintrag, CHANGELOG-Eintrag.

## 3. Befehlsformat

Vor jedem Befehl angeben:

```
EXPECTED=<realistische Laufzeit>
TARGET=<STAGE|LIVE|SERVER>
MODE=<READ_ONLY|WRITE>
```

- Befehle kurz, robust, terminalfest. Komplexe Änderungen in wenige klare Schritte teilen. Keine Monster-Bashs, keine unnötigen Audit-Schleifen.
- VERBOTEN: `exit`, `logout`, `exec >(...)`, Process Substitution, `/dev/fd/*`, unnötige Subshells, riskante verschachtelte Heredocs, komplexe Quote-Konstruktionen.
- Die SSH-Session darf nie absichtlich beendet werden.
- Bei Fehler: STATUS ausgeben, falls nötig Rollback, normal zurück zum Prompt.

## 4. Resultat und Report

Das OSC52-Kopieren entfällt in SSH-Sitzungen. Das Resultat wird im Chat ausgegeben, vollständige Details in einen Report auf dem Server.

Standard-Resultat:

```
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
```

Nur unmittelbar entscheidungsrelevante Zusatzwerte ergänzen.

## 5. STAGE / LIVE

| | Pfad |
|---|---|
| STAGE | `/home/u928734560/domains/stage.studioctrl.ch/public_html` |
| LIVE | `/home/u928734560/domains/studioctrl.ch/public_html` |

- DB-Zugang immer aus `TARGET_ROOT/app/config/parameters.php`. Niemals DB-Namen, Prefix oder Credentials zwischen Stage und LIVE übernehmen oder hart codieren.
- TARGET_ROOT bestimmt Installation, DB, Prefix, Konfiguration, Cache und Templates.
- Stage und LIVE haben getrennte Rollbacks.
- Vor jedem WRITE muss TARGET eindeutig feststehen.
- **LIVE nur ändern, wenn TARGET ausdrücklich LIVE ist und Tonino im Chat freigegeben hat.**
- Keine Credentials in dieser Datei, in Reports oder im Chat.

## 6. CSS (Source of Truth)

- SunnyToo Theme Editor, Custom codes, Custom CSS Code. DB-Key: `STSN_CUSTOM_CSS`.
- Der Key existiert je Environment getrennt (Stage und LIVE sind zwei Werte).
- Normale CSS-Änderungen gehören in `STSN_CUSTOM_CSS`. Keine zusätzlichen `studioctrl-brands-vXX.css`, Inline-CSS-Blöcke oder Template-Overrides, wenn es sauber darüber geht.
- Bestehende Regeln konsolidieren und kommentieren, keine konkurrierenden Overrides.
- Ablauf CSS-WRITE: aktuellen Wert sichern, Candidate validieren, TARGET prüfen, DB aus TARGET_ROOT auflösen, schreiben, Readback, SHA256/Inhalt validieren, Frontend validieren, Healthcheck, Changelog, Rollback dokumentieren.

## 7. JavaScript / Tracking

- `CUSTOM_CODE_POLICY.md` ist verbindlich.
- Keine DB-Keys für JS, Tracking, Analytics oder Pixels erraten. Zuerst die tatsächliche Source of Truth feststellen (SunnyToo-Feld, Modul, GTM, Template oder andere Integration).
- Keine parallelen Implementierungen.

## 8. Projektstand

> **TODO (Tonino):** Dieser Abschnitt ist nur ein Gerüst. Der Projektstand stand in der CLAUDE.md auf deinem PC (`C:\Users\Tonino\studioctrl-server`) und ist hier nicht vorhanden. Bitte aus der Original-Datei einfügen.

### Erledigt

- (aus Original übernehmen)

### Offene Schritte

1. Schritt 1: (aus Original übernehmen)
2. (weitere Schritte)

### Dateien im Arbeitsordner

- `geoconvado-1.7.3.zip`: Modul, Version 1.7.3. Zweck und Installationsziel aus Original ergänzen.
- `gff.sh`: ffmpeg-Bash. Zweck aus Original ergänzen.

## 9. Start

Erste Nachricht in neuer Sitzung: «Lies CLAUDE.md und führe Schritt 1 aus.»
