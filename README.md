# AI Translator für TYPO3 (netwerk/typo3-ai-translator)

Übersetzt Seiten und Inhaltselemente in TYPO3 14 automatisch mit **DeepL**, **ChatGPT (OpenAI)** oder **Claude (Anthropic)**.

- Neue Option **„KI-Übersetzung mit …“** im Übersetzen-Dialog des Seiten-Moduls (für Seiten und einzelne Inhaltselemente)
- Übersetzt alle sichtbaren Textfelder: Überschriften, Texte (RTE inkl. `title`/`alt`-Attribute), Link-Beschriftungen und **Link-Titel**, Inline-Kinder (z. B. FAQ-Einträge, Bild-Metadaten am Inhaltselement)
- Legt fehlende Seitenübersetzungen an und übersetzt die **Seiteneigenschaften** inkl. SEO-Feldern; der **Slug** wird aus dem übersetzten Titel neu erzeugt
- **Erkennt veraltete Übersetzungen**: Ändert sich der deutsche Text, zeigt das Seiten-Modul einen Hinweis mit Schaltfläche „Mit … aktualisieren“, betroffene Inhaltselemente erhalten ein Badge „Übersetzung veraltet“
- **Nachübersetzen nur des Geänderten**: geänderte Felder, neue Unterelemente (z. B. eine neue FAQ-Frage) und noch nicht übersetzte Inhalte – unveränderte, von Hand angepasste Texte bleiben erhalten
- Backend-Modul **Inhalt → KI-Übersetzung**
  - *Übersicht & Massenübersetzung*: Übersetzungsstand aller Seiten je Sprache (nicht übersetzt / veraltet / unvollständig / aktuell), Auswahl mehrerer Seiten, Abarbeitung über eine Warteschlange mit Fortschrittsanzeige oder per Cronjob
  - *Anbieter & Protokoll*: Status der Anbieter, Verbindungstest, Verbrauch der letzten 30 Tage, Protokoll aller Übersetzungen
- Übersetzt auch eigenständige Datensätze in Ordnern (z. B. Blog-Kategorien und Schlagwörter)
- **Kosten im Griff**: Schätzung in Euro schon bei der Auswahl im Modul, Kosten je Übersetzung im Protokoll, optionales Monatslimit (die Warteschlange hält bei Erreichen an)
- **Prüf-Workflow**: KI-Übersetzungen sind als „KI-übersetzt – bitte prüfen“ gekennzeichnet, bis ein Redakteur sie speichert; neue Seitenübersetzungen können verborgen angelegt werden
- **Einstellungen pro Website** (Site Set): Anrede, Stil, Glossar und „Nicht übersetzen“ je Website, Glossar auch je Sprache
- **Glossar für alle Anbieter**: bei DeepL über die Glossar-Funktion der API
- **Robust**: automatische Wiederholung bei Überlastung der Anbieter (429/5xx), Unit- und Functional-Tests
- **Datei-Metadaten** (Titel, Alternativtext, Beschreibung): im Übersetzen-Dialog der Dateiliste, per Aktion je Datei (veraltete Übersetzungen werden in der Dateiliste hervorgehoben) und als Massenübersetzung im Modul-Reiter *Dateien (Metadaten)*

Nicht übersetzt werden Felder mit `l10n_mode = exclude`, schreibgeschützte Felder, Code-/HTML-Editoren, JSON-Felder, Zahlen-/Datums-/E-Mail-Felder, Autorennamen und Social-Media-Handles, technische Verknüpfungsfelder sowie alles, was in der Konfiguration unter „Ausgeschlossene Felder“ steht. Bei Bildreferenzen werden nur Titel, Alternativtext, Beschreibung und Link übersetzt.

## Voraussetzungen

- TYPO3 14.3, PHP 8.2+
- Mindestens ein API-Key (siehe [API-Keys besorgen](#api-keys-besorgen))

## Installation (DDEV)

```bash
ddev composer config repositories.typo3-ai-translator git git@github.com:netwerk-kreidl/typo3-ai-translator.git
ddev auth ssh
ddev composer require netwerk/typo3-ai-translator:^1.0
ddev typo3 extension:setup --extension=ai_translator
```

`ddev auth ssh` reicht den lokalen SSH-Agent in den Container durch, damit Composer das Repository per SSH klonen kann.
`extension:setup` legt die Tabellen `tx_aitranslator_log`, `tx_aitranslator_state` und `tx_aitranslator_queue` an.

Update auf eine neue Version: `ddev composer update netwerk/typo3-ai-translator` und danach erneut `extension:setup`.

### Deployment

Nach dem Deployment `typo3 extension:setup` ausführen (bzw. den Database Analyzer), damit neue Tabellen angelegt werden.

Das Repository ist öffentlich, Composer lädt es deshalb auch auf dem Server und in GitHub Actions ohne Zugangsdaten.
Wird es wieder privat geschaltet, braucht jede Stelle, an der `composer install` läuft, Lesezugriff:
GitHub Actions z. B. über einen Fine-grained Token (*Contents: Read-only*) als `COMPOSER_AUTH`, der Server über einen Deploy-Key.

### Cronjob für die Massenübersetzung (optional)

Die Warteschlange wird im Modul abgearbeitet, solange die Seite geöffnet ist. Für große Mengen oder unbeaufsichtigt per Cronjob, z. B. alle 5 Minuten:

```bash
vendor/bin/typo3 ai-translator:queue:process --time-limit=240
```

`--limit=N` begrenzt die Anzahl Seiten pro Lauf. Einträge, die länger als 15 Minuten „in Arbeit“ hängen, werden automatisch erneut versucht.

## Konfiguration

**Admin Tools → Einstellungen → Extension-Konfiguration → ai_translator**

| Einstellung | Bedeutung |
|---|---|
| Standard-Anbieter | Wird im Übersetzen-Dialog zuerst angeboten (Standard: DeepL) |
| Anrede | Sie / du / neutral – gilt für alle Anbieter, sofern die Zielsprache das unterscheidet |
| Stil-Hinweis | Nur ChatGPT/Claude: Ton und Zielgruppe, z. B. „Gehobener, herzlicher Ton eines 4-Sterne-Boutiquehotels in Wien“ |
| Nicht übersetzen | Kommagetrennte Begriffe, die unverändert bleiben (Markennamen) |
| Glossar | `Begriff=Übersetzung;Begriff2=Übersetzung2`, nur für eine Sprache `en: Begriff=Übersetzung` – gilt für alle Anbieter |
| Ausgeschlossene Felder | `tabelle.feld` oder `*.feld`, kommagetrennt |
| Neue Seitenübersetzungen verbergen | Neu angelegte Seitenübersetzungen bleiben verborgen, bis sie geprüft sind |
| Monatslimit | Euro pro Kalendermonat, 0 = kein Limit (siehe [Kosten](#kosten)) |
| Preise | Richtwerte für die Kostenanzeige: DeepL in € je 1 Mio. Zeichen, ChatGPT/Claude in US-$ je 1 Mio. Tokens, Umrechnungskurs |
| API-Keys, Modelle | je Anbieter, siehe unten |

### Einstellungen pro Website

Stil, Glossar, „Nicht übersetzen“, Anrede und „Neue Seitenübersetzungen verbergen“ lassen sich je Website festlegen. Dazu das Site Set in der Site-Konfiguration ergänzen:

```yaml
# config/sites/<website>/config.yaml
dependencies:
  - netwerk/ai-translator
```

Danach unter **Site Management → Einstellungen → KI-Übersetzung** pflegen (oder in `config/sites/<website>/settings.yaml`):

```yaml
aiTranslator:
  styleHint: 'Gehobener, herzlicher Ton eines 4-Sterne-Boutiquehotels in Wien'
  doNotTranslate:
    - 'Josefshof'
  glossary: |
    Zimmer & Suiten=Rooms & Suites
    en: Frühstück=breakfast
```

Leere Werte übernehmen die globale Extension-Konfiguration; Listen und Glossar werden mit ihr zusammengeführt (Einträge der Website haben Vorrang).

**DeepL-Glossar**: Die Extension legt das Glossar bei DeepL automatisch an (je Sprachpaar und Inhalt, höchstens fünf je Sprachpaar) und erzeugt es neu, sobald sich die Einträge ändern. Unterstützt DeepL ein Sprachpaar nicht, wird ohne Glossar übersetzt (Hinweis im TYPO3-Log).

### API-Keys per Umgebungsvariable (empfohlen)

API-Keys gehören nicht ins Repository. Umgebungsvariablen haben Vorrang vor der Extension-Konfiguration:

```dotenv
AI_TRANSLATOR_DEEPL_API_KEY=...
AI_TRANSLATOR_OPENAI_API_KEY=...
AI_TRANSLATOR_ANTHROPIC_API_KEY=...
# optional: deepl | openai | anthropic
AI_TRANSLATOR_DEFAULT_PROVIDER=deepl
```

Lokal z. B. in `.ddev/.env` (danach `ddev restart`) oder in der `.env` des Projekts, am Server in der `.env` im Shared-Verzeichnis.
Das Modul zeigt bei jedem Anbieter, ob der Key aus der Umgebung kommt.

### Berechtigungen

Administratoren dürfen immer übersetzen. Für Redakteure in der Backend-Benutzergruppe unter
*Zugriffsrechte → Benutzerdefinierte Optionen → KI-Übersetzung → „KI-Übersetzung verwenden“* freischalten.
Damit sehen sie die Optionen im Übersetzen-Dialog und die Schaltfläche „Aktualisieren“ im Seiten-Modul; den Hinweis auf veraltete Übersetzungen sehen alle Redakteure.
Zusätzlich braucht die Gruppe wie gewohnt Schreibrechte auf Seiten/Inhalte und die Zielsprache.
Das Modul „KI-Übersetzung“ wird wie jedes andere Modul in der Gruppe freigegeben.

## API-Keys besorgen

### DeepL (Standard)

1. Konto unter <https://www.deepl.com/pro-api> anlegen – **DeepL API Free** (500.000 Zeichen/Monat gratis) oder **DeepL API Pro** (nutzungsabhängig). Achtung: ein normales „DeepL Pro“-Abo für die Web-App enthält **keinen** API-Zugang.
2. Im Konto unter **API Keys** einen Schlüssel erstellen und kopieren.
3. Free-Keys enden auf `:fx` – die Extension verwendet dann automatisch `api-free.deepl.com`.

Datenschutz: Bei DeepL API Pro werden Texte nicht gespeichert und nicht für Training verwendet; Auftragsverarbeitungsvertrag in den Kontoeinstellungen. Bei API Free behält sich DeepL vor, Texte zur Verbesserung zu nutzen – für Kundenprojekte Pro verwenden.

### OpenAI (ChatGPT)

1. Unter <https://platform.openai.com> anmelden (unabhängig von einem ChatGPT-Plus-Abo – das enthält keine API-Nutzung).
2. **Settings → Billing**: Zahlungsmethode hinterlegen und Guthaben aufladen, sinnvollerweise ein Limit setzen.
3. **API keys → Create new secret key**, am besten einem eigenen Projekt zuordnen, Berechtigung reicht „Restricted“ mit Zugriff auf *Model capabilities → Chat completions*.
4. Modell in der Extension-Konfiguration (Standard `gpt-5`) bei Bedarf anpassen.

Datenschutz: API-Daten werden laut OpenAI nicht zum Training verwendet; DPA unter <https://openai.com/policies/data-processing-addendum>.

### Anthropic (Claude)

1. Unter <https://console.anthropic.com> anmelden bzw. Organisation anlegen.
2. **Settings → Billing**: Guthaben kaufen (Prepaid), optional Ausgabenlimit unter *Limits* setzen.
3. **Settings → API Keys → Create Key**, idealerweise in einem eigenen Workspace pro Kunde, damit Verbrauch und Limits getrennt sind.
4. Modell (Standard `claude-opus-5-5`) und „Sorgfalt“ (effort, Standard *niedrig* – für Übersetzungen ausreichend, schnell und günstig) in der Extension-Konfiguration.

Hinweis: Lehnt das gewählte Claude-Modell eine Anfrage ab, beantwortet die API sie automatisch mit einem Ersatzmodell (serverseitiger Fallback, standardmäßig aktiv). Im Protokoll steht jeweils das tatsächlich verwendete Modell.

Datenschutz: API-Eingaben werden von Anthropic nicht zum Training verwendet; DPA ist Teil der Commercial Terms.

### Kosten

Das Modul zeigt bei der Auswahl von Seiten oder Dateien eine **Schätzung** (Zeichen und Euro), im Reiter „Anbieter & Protokoll“ die Kosten je Übersetzung und die Summe der letzten 30 Tage.
Mit einem **Monatslimit** (Extension-Konfiguration → Kosten) wird keine Übersetzung gestartet, die das Limit überschreiten würde – die Warteschlange hält an und kann nach Anheben des Limits fortgesetzt werden.
Die Beträge sind Richtwerte aus den hinterlegten Preisen (Standard: DeepL API Pro 20 €/1 Mio. Zeichen, Claude Opus 5.5 4 $/20 $ je 1 Mio. Tokens). Maßgeblich ist die Abrechnung des Anbieters; bei Preisänderungen die Werte in der Extension-Konfiguration anpassen.

Eine typische Hotelseite mit 8–10 Inhaltselementen hat etwa 5.000–10.000 Zeichen. Bei DeepL Pro sind das wenige Cent pro Seite, bei ChatGPT/Claude je nach Modell ähnlich bis etwas mehr. Das Modul zeigt Zeichen bzw. Tokens pro Übersetzung und die Summen der letzten 30 Tage; die genauen Preise stehen auf den Preisseiten der Anbieter.

## Welcher Anbieter?

- **DeepL**: sehr gute, wörtlichere Übersetzungen, HTML-sicher, günstig – Standard.
- **ChatGPT / Claude**: freiere, stilistisch angepasste Übersetzungen; berücksichtigen Stil-Hinweis, Glossar und Feldkontext (z. B. Überschrift vs. Linktext, maximale Länge von SEO-Titeln).

Alle Anbieter erhalten nur die zu übersetzenden Texte – keine Benutzerdaten.

## Verwendung

1. Modul **Seite** → deutsche Seite wählen → **Übersetzen** (bzw. neue Übersetzung über die Sprachauswahl).
2. Im Dialog **„KI-Übersetzung mit DeepL“** (oder ChatGPT/Claude) wählen, Inhaltselemente auswählen, bestätigen.
3. Fehlt die Seitenübersetzung, wird sie angelegt (inkl. Seiteneigenschaften und Slug); danach werden die Inhalte übersetzt.
4. Ergebnis prüfen – die Übersetzungen sind normale Datensätze und können frei bearbeitet werden.

Der Modus (verbundene Übersetzung oder freie Kopie) richtet sich nach der Auswahl im Dialog bzw. dem bestehenden Modus der Seite.

### Veraltete Übersetzungen

Ein Feld gilt als veraltet, wenn sich der Ausgangstext seit der Übersetzung geändert hat:

- **Verbundene Übersetzungen** (Standard): Vergleich mit dem Stand, den TYPO3 beim Speichern der Übersetzung festhält (`l10n_diffsource`). Das funktioniert auch für Inhalte, die von Hand übersetzt wurden. Wie im TYPO3-Standard gilt eine Übersetzung als geprüft, sobald sie im Formular gespeichert wird.
- **Freie Übersetzungen** (Kopien): Vergleich mit dem Fingerabdruck, den die KI-Übersetzung gespeichert hat; wurde das übersetzte Feld seitdem von Hand geändert, gilt es als erledigt.
- **Neue Unterelemente** (z. B. FAQ-Fragen, Bilder) und **noch nicht übersetzte Inhaltselemente** werden ebenfalls gemeldet und beim Aktualisieren ergänzt.

Beim Aktualisieren wird die URL (Slug) bestehender Übersetzungen **nicht** verändert, damit keine Links brechen. Soll sie zum neuen Titel passen, in den Seiteneigenschaften der Übersetzung neu erzeugen (TYPO3 legt dabei eine Weiterleitung an).

### Prüfen der KI-Übersetzung

Jede KI-Übersetzung ist zunächst „ungeprüft“:

- Im Seiten-Modul tragen die Elemente das Kennzeichen **„KI-übersetzt – bitte prüfen“**, oben steht ein Hinweis mit der Anzahl.
- Sobald ein Redakteur das Element (oder die Seiteneigenschaften) speichert, gilt es als geprüft. Alternativ: **„Alles auf dieser Seite als geprüft markieren“**.
- In der Modul-Übersicht steht je Seite die Zahl ungeprüfter Elemente, im Reiter „Dateien“ je Datei.
- Mit „Neue Seitenübersetzungen verbergen“ bleiben neu angelegte Seiten unsichtbar, bis sie jemand sichtbar schaltet.

### Datei-Metadaten

Titel, Alternativtext und Beschreibung einer Datei gelten überall, wo die Datei verwendet wird (sofern am Inhaltselement nichts Eigenes eingetragen ist). Übersetzen:

- **Dateiliste → Übersetzungen → Sprache**: Im Übersetzen-Dialog „KI-Übersetzung mit …“ wählen (für noch nicht übersetzte Metadaten).
- **Dateiliste (Listenansicht)**: Ist die Übersetzung veraltet, erscheint bei der Datei eine Schaltfläche mit Warnsymbol („… aktualisieren“); fehlt sie, steht „Metadaten mit … übersetzen“ im Aktionsmenü. In der Kachelansicht zeigt TYPO3 keine Datei-Aktionen.
- **Modul → Dateien (Metadaten)**: Ordner und Sprache wählen, Dateien anhaken, über die Warteschlange übersetzen. Daneben stehen der deutsche und der übersetzte Text zum Gegenlesen.

Dateien ohne deutsche Texte werden übersprungen. Redakteure brauchen zusätzlich Schreibrechte auf die Tabelle „Metadaten“ (`sys_file_metadata`) und die Dateifreigabe.

### Massenübersetzung

Modul **Inhalt → KI-Übersetzung → Übersicht & Massenübersetzung**: Sprache wählen, Seiten mit Handlungsbedarf anhaken, Anbieter wählen, „In Warteschlange stellen & starten“.
Fehlende Seiten werden samt Inhalten angelegt, bestehende nur aktualisiert. Ordner mit Datensätzen (z. B. Blog-Kategorien) erscheinen ebenfalls in der Liste.
Übersetzt wird von oben nach unten im Seitenbaum. Übergeordnete Seiten deshalb mit auswählen, damit die URL von Unterseiten den übersetzten Pfad erhält (z. B. `/en/rooms-suites/junior-suite/`).

Für Tests ohne API-Key gibt es im Kontext **Development** zusätzlich den Anbieter „Test-Übersetzung (ohne KI)“, der Texte nur mit `[EN] ` voranstellt.

## Fehlerbehebung

- **Option fehlt im Dialog**: Kein API-Key für den Anbieter, oder dem Benutzer fehlt die Berechtigung „KI-Übersetzung verwenden“.
- **Kein Hinweis im Seiten-Modul**: Der Hinweis erscheint nur für bereits angelegte Seitenübersetzungen und Sprachen, für die der Benutzer Rechte hat.
- **401/403**: API-Key falsch oder (DeepL) Free-Key gegen Pro-Endpoint bzw. umgekehrt – im Modul „Verbindung testen“.
- **429 / Kontingent**: Die Extension versucht es automatisch bis zu dreimal mit Wartezeit erneut. Bleibt der Fehler, ist das Limit beim Anbieter erreicht – später erneut versuchen oder Guthaben aufladen.
- **„Monatslimit erreicht“**: Limit in der Extension-Konfiguration anheben, danach in der Warteschlange „Abarbeiten“ klicken.
- Fehler werden im Modul-Protokoll und in `var/log/typo3_*.log` festgehalten.

## Entwicklung & Tests

```bash
composer install            # installiert TYPO3 und das Testing-Framework nach .Build/
composer test:unit
composer test:functional    # SQLite, keine weitere Datenbank nötig
```

Die Tests laufen bei jedem Push per GitHub Actions (PHP 8.2 und 8.5). Die Functional-Tests übersetzen mit dem Test-Anbieter gegen eine echte Datenbank: Anlegen von Seiten und Inhalten, technische Felder von Bildreferenzen, Erkennen und Nachübersetzen geänderter Felder, Prüf-Status, Datei-Metadaten und Monatslimit.

## Roadmap

- **1.1** ✓ Hinweis auf veraltete Übersetzungen, Übersicht pro Sprache, Nachübersetzen geänderter Felder, Massenübersetzung
- **1.2** ✓ Datei-Metadaten (Titel, Alternativtext, Beschreibung) übersetzen
- **1.3** ✓ Kostenschätzung und Monatslimit, Einstellungen pro Website, DeepL-Glossar, Prüf-Workflow, automatische Wiederholung, Tests

## Lizenz

Proprietär – netwerk. Nutzung nur in Projekten der Agentur.
