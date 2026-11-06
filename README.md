# AI Translator für TYPO3 (netwerk/typo3-ai-translator)

Übersetzt Seiten und Inhaltselemente in TYPO3 14 automatisch mit **DeepL**, **ChatGPT (OpenAI)** oder **Claude (Anthropic)**.

- Neue Option **„KI-Übersetzung mit …“** im Übersetzen-Dialog des Seiten-Moduls (für Seiten und einzelne Inhaltselemente)
- Übersetzt alle sichtbaren Textfelder: Überschriften, Texte (RTE inkl. `title`/`alt`-Attribute), Link-Beschriftungen und **Link-Titel**, Inline-Kinder (z. B. FAQ-Einträge, Bild-Metadaten am Inhaltselement)
- Legt fehlende Seitenübersetzungen an und übersetzt die **Seiteneigenschaften** inkl. SEO-Feldern; der **Slug** wird aus dem übersetzten Titel neu erzeugt
- Backend-Modul **Inhalt → KI-Übersetzung**: Status der Anbieter, Verbindungstest, Verbrauch der letzten 30 Tage, Protokoll aller Übersetzungen
- Speichert pro übersetztem Feld einen Hash des Ausgangstexts (Grundlage für die Erkennung veralteter Übersetzungen in Version 1.1)

Nicht übersetzt werden Felder mit `l10n_mode = exclude`, schreibgeschützte Felder, Code-/HTML-Editoren, Zahlen-/Datums-/E-Mail-Felder sowie alles, was in der Konfiguration unter „Ausgeschlossene Felder“ steht.

## Voraussetzungen

- TYPO3 14.3, PHP 8.2+
- Mindestens ein API-Key (siehe [API-Keys besorgen](#api-keys-besorgen))
- Lesezugriff auf das private Repository `netwerk-kreidl/typo3-ai-translator`

## Installation (DDEV)

```bash
ddev composer config repositories.typo3-ai-translator git git@github.com:netwerk-kreidl/typo3-ai-translator.git
ddev auth ssh
ddev composer require netwerk/typo3-ai-translator:^1.0
ddev typo3 extension:setup --extension=ai_translator
```

`ddev auth ssh` reicht den lokalen SSH-Agent in den Container durch, damit Composer das private Repository klonen kann.
`extension:setup` legt die Tabellen `tx_aitranslator_log` und `tx_aitranslator_state` an.

### Deployment

Auch der Build- bzw. Zielserver muss das private Repository lesen können:

- **Composer läuft am Server** (z. B. Deployer): öffentlichen SSH-Schlüssel des Servers im Repository unter *Settings → Deploy keys* eintragen (nur Lesezugriff). Ein Deploy-Key gilt jeweils nur für ein Repository – für jede private Extension einen eigenen Schlüssel oder einen Maschinen-User mit Lesezugriff auf alle verwenden.
- **Composer läuft in GitHub Actions**: einen Deploy-Key als Secret hinterlegen und per `webfactory/ssh-agent` laden (mehrere Schlüssel sind möglich).

Nach dem Deployment `typo3 extension:setup` ausführen (bzw. den Database Analyzer), damit die Tabellen angelegt werden.

## Konfiguration

**Admin Tools → Einstellungen → Extension-Konfiguration → ai_translator**

| Einstellung | Bedeutung |
|---|---|
| Standard-Anbieter | Wird im Übersetzen-Dialog zuerst angeboten (Standard: DeepL) |
| Anrede | Sie / du / neutral – gilt für alle Anbieter, sofern die Zielsprache das unterscheidet |
| Stil-Hinweis | Nur ChatGPT/Claude: Ton und Zielgruppe, z. B. „Gehobener, herzlicher Ton eines 4-Sterne-Boutiquehotels in Wien“ |
| Nicht übersetzen | Kommagetrennte Begriffe, die unverändert bleiben (Markennamen) |
| Glossar | Nur ChatGPT/Claude: `Begriff=Übersetzung;Begriff2=Übersetzung2` |
| Ausgeschlossene Felder | `tabelle.feld` oder `*.feld`, kommagetrennt |
| API-Keys, Modelle | je Anbieter, siehe unten |

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
*Zugriffsrechte → Benutzerdefinierte Optionen → KI-Übersetzung → „Mit KI übersetzen“* freischalten.
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

### Kosten (grobe Orientierung)

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

Für Tests ohne API-Key gibt es im Kontext **Development** zusätzlich den Anbieter „Test-Übersetzung (ohne KI)“, der Texte nur mit `[EN] ` voranstellt.

## Fehlerbehebung

- **Option fehlt im Dialog**: Kein API-Key für den Anbieter, oder dem Benutzer fehlt die Berechtigung „Mit KI übersetzen“.
- **401/403**: API-Key falsch oder (DeepL) Free-Key gegen Pro-Endpoint bzw. umgekehrt – im Modul „Verbindung testen“.
- **429 / Kontingent**: Limit beim Anbieter erreicht; später erneut versuchen oder Guthaben aufladen.
- Fehler werden im Modul-Protokoll und in `var/log/typo3_*.log` festgehalten.

## Roadmap

- **1.1** – Hinweis auf veraltete Übersetzungen (Ausgangstext geändert) im Seiten-Modul, Übersicht pro Sprache im Modul, gezieltes Nachübersetzen geänderter Felder, Massenübersetzung über eine Warteschlange
- **1.2** – Dateimetadaten (Titel, Alternativtext, Beschreibung) in der Dateiliste übersetzen

## Lizenz

Proprietär – netwerk. Nutzung nur in Projekten der Agentur.
