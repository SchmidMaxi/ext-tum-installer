# TUM Installer Extension - Arbeitsstand & Weiterentwicklung

## Aktuelle Extension-Struktur

### Übersicht
- **Extension Key:** `installer`
- **Namespace:** `Tum\Installer\`
- **TYPO3 Version:** 14.x
- **PHP:** ^8.4

### Verzeichnisstruktur
```
packages/installer/
├── Classes/
│   ├── Command/RunSetupCommand.php           # CLI-Interface
│   ├── Controller/BackendInstallerController.php  # Backend-Modul
│   ├── Domain/
│   │   ├── Model/
│   │   │   ├── InstallationConfig.php        # Konfigurations-DTO
│   │   │   └── SetupType.php                 # Enum (Setup1, Setup3, Archiv, Standalone)
│   │   └── Strategy/
│   │       ├── SetupStrategyInterface.php    # Strategy-Interface
│   │       ├── AbstractStrategy.php          # Basis-Methoden
│   │       ├── Setup1Strategy.php            # Root-Site Setup
│   │       ├── Setup3Strategy.php            # Zusätzliche Projekte
│   │       ├── ArchivStrategy.php            # Archiv/Subdirectory Setup
│   │       └── StandaloneStrategy.php        # Standalone-Installation
│   └── Service/
│       ├── InstallerService.php              # Haupt-Orchestrierung
│       └── Worker/
│           ├── DatabaseImporterService.php   # DB-Import aus YAML
│           ├── YamlLoaderService.php         # YAML-Parsing
│           ├── FolderStructureService.php    # Ordner-Erstellung
│           └── SiteConfigurationService.php  # Site-Config Generierung
├── Configuration/
│   ├── Backend/Modules.php                   # Backend-Modul Registrierung
│   ├── Services.yaml                         # Dependency Injection
│   └── Installer/*.yaml                      # Setup-Konfigurationen
└── Resources/
    └── Private/Templates/BackendInstaller/Index.html  # Formular-Template
```

### Aktueller Formular-Ablauf
1. Formular zeigt alle Felder in einem Single-Step
2. `executeAction()` im Controller empfängt alle Daten
3. `InstallerService->install()` führt kompletten Setup durch
4. Erfolgs-/Fehlermeldung via FlashMessage

### Aktuelle Formular-Bereiche
1. **Basis Daten:** Setup-Typ, WID, Domain, Projektname
2. **Übergeordnete Einheit:** School/OU, Pfad (bei Archiv)
3. **Kontakt & Footer:** Impressum, Barrierefreiheit
4. **Extensions & Features:** News, Intropage, etc. (nur Setup1/3/Standalone)
5. **Tracking:** Matomo-ID (nur Setup1/3/Standalone)

---

## Nächste Schritte: Mehrstufiges Formular + Webinfo-Schnittstelle

### Ziel
1. **Schritt 1:** Bisherige Daten eingeben → Seiten anlegen wie bisher
2. **Schritt 2:** Zusätzliche Daten für Webinfo eintragen
3. **Daten** via API an `tum/webinfo` Extension pushen

### Architektur-Überblick

```
┌─────────────────────────────────────────────────────────────┐
│                    TUM Installer (mehrere Systeme)          │
│                                                             │
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────────┐ │
│  │  Schritt 1  │───▶│  Schritt 2  │───▶│ API-Client      │ │
│  │  (Setup)    │    │  (Webinfo)  │    │ Push zu Webinfo │ │
│  └─────────────┘    └─────────────┘    └────────┬────────┘ │
│                                                  │          │
└──────────────────────────────────────────────────┼──────────┘
                                                   │
                                                   ▼ HTTPS (*.tum.de only)
┌─────────────────────────────────────────────────────────────┐
│                TUM Webinfo (Zentrales System)               │
│                                                             │
│  ┌─────────────────┐                                        │
│  │  API-Endpoint   │◀── Daten empfangen & speichern         │
│  │  (abgesichert)  │                                        │
│  └─────────────────┘                                        │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Implementierungsplan

#### Phase 1: Mehrstufiges Formular im Backend-Modul

**1.1 Controller erweitern** (`BackendInstallerController.php`)
- Neue Actions hinzufügen:
  - `indexAction()` → Schritt 1 (bestehend, anpassen)
  - `executeAction()` → Schritt 1 ausführen, Redirect zu Schritt 2
  - `step2Action()` → Schritt 2 anzeigen (neu)
  - `finalizeAction()` → Schritt 2 ausführen, API-Call (neu)
- Session/State-Management für Formular-Daten zwischen Schritten

**1.2 Neue Templates erstellen**
- `Index.html` anpassen → Schritt 1 mit Fortschrittsanzeige
- `Step2.html` erstellen → Webinfo-Daten Formular
- Optional: AJAX-basierter Ladestatus während Schritt 1

**1.3 Webinfo-Datenmodell definieren**
- Welche Daten werden für Webinfo benötigt?
- Mapping: Installer-Daten → Webinfo-Daten

#### Phase 2: API-Client im Installer

**2.1 Neuer Service** (`Classes/Service/WebinfoApiService.php`)
```php
class WebinfoApiService
{
    public function pushInstallationData(InstallationConfig $config, WebinfoData $data): bool;
    private function buildRequestPayload(InstallationConfig $config, WebinfoData $data): array;
    private function getApiEndpoint(): string;
    private function getApiKey(): string;
}
```

**2.2 Konfiguration in `additional.php`**
```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['installer']['webinfoApi'] = [
    'endpoint' => 'https://webinfo.tum.de/api/v1/installation',
    'apiKey' => 'SECRET_KEY_HERE',
    'enabled' => true,
];
```

#### Phase 3: API-Endpoint in tum/webinfo (spätere Extension)

**3.1 Endpoint erstellen**
- Route: `POST /api/v1/installation`
- Authentifizierung: API-Key + Domain-Whitelist

**3.2 Domain-Whitelisting**
```php
// Middleware oder Controller-Check
$allowedPattern = '/\.tum\.de$/i';
$requestDomain = $_SERVER['HTTP_HOST'] ?? '';
if (!preg_match($allowedPattern, $requestDomain)) {
    return new JsonResponse(['error' => 'Forbidden'], 403);
}
```

**3.3 Konfiguration in `additional.php` (Webinfo-System)**
```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['webinfo']['api'] = [
    'allowedDomains' => ['*.tum.de'],
    'requireApiKey' => true,
];
```

---

## Spezifikation: Schritt 2 - Webinfo-Daten

### Formularfelder Schritt 2

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| **Umgebung** | Select | www-v23, www-v19, www-v8 |
| **Organization Unit** | Text (Freitext) | Organisationseinheit |
| **Website-Type** | Select | Einrichtung, Forschungsgruppe, Kooperation, Projekt, Studiengang, Sonstiges |
| **TYPO3 Version** | Select (vorausgefüllt) | v12, v14 (nur Major-Version, aus System ermittelt) |
| **Laufzeit bis** | Date | Enddatum der Website-Laufzeit |
| **Nach Laufzeitende** | Select | Archiv, Löschen, Verlängern |
| **Notiz** | Textarea | Freitext für Anmerkungen |

### Select-Optionen (definiert)

**Umgebung:**
```
- www-v23
- www-v19
- www-v8
```

**Website-Type:**
```
- Einrichtung
- Forschungsgruppe
- Kooperation
- Projekt
- Studiengang
- Sonstiges
```

**Nach Laufzeitende:**
```
- Archiv
- Löschen
- Verlängern
```

**Organization Unit:** Freitext-Eingabe

---

## Spezifikation: API-Daten an Webinfo

### Payload-Struktur

```json
{
  // Aus Schritt 1
  "url": "{domain}/{navName}",
  "setup": "Setup1|Setup3|Standalone|Archiv",

  // Aus Schritt 2
  "websiteType": "...",
  "environment": "...",
  "organizationUnit": "...",
  "typo3Version": "v12|v14",
  "createdAt": "2026-01-30",
  "validUntil": "2027-01-30",
  "afterExpiry": "archive|delete|extend",
  "note": "Freitext..."
}
```

### Mapping zur Webinfo-Datenbank
- `createdAt` → Feld "In TYPO3 seit"
- `typo3Version` → Feld "TYPO3 <Version>"

---

## Spezifikation: Ladestatus Schritt 1

### Technische Umsetzung
- **AJAX-basiert** mit EventSource (Server-Sent Events) oder Polling
- **Ladeanimation:** Spinner/Kreis über dem Status
- **Live-Status-Updates** während der Installation

### Status-Schritte anzeigen

```
┌─────────────────────────────────────────┐
│           ◠◡◠  (Lade-Animation)         │
│                                         │
│  ✓ Validierung abgeschlossen            │
│  ✓ Ordnerstruktur erstellt              │
│  ● Lege Seiten an...                    │
│  ○ Erstelle BE-Groups                   │
│  ○ Erstelle BE-Users                    │
│  ○ Konfiguriere Site                    │
│  ○ Generiere TypoScript                 │
└─────────────────────────────────────────┘
```

### Implementierungsansatz

**Option A: Server-Sent Events (SSE)**
```php
// Controller sendet Events während Installation
public function executeWithProgressAction(): ResponseInterface
{
    return new StreamedResponse(function() {
        $this->installerService->installWithProgress($config, function($step) {
            echo "data: " . json_encode(['step' => $step]) . "\n\n";
            ob_flush();
            flush();
        });
    });
}
```

**Option B: Session-basiertes Polling**
```php
// Installation läuft, schreibt Status in Session
// Frontend pollt /status-Endpoint alle 500ms
```

**Empfehlung:** SSE ist eleganter, aber Polling ist robuster bei Proxy-Umgebungen.

---

## Offene Fragen / Zu klären

### Select-Optionen
- [x] Welche Werte für "Umgebung"? → www-v23, www-v19, www-v8
- [x] Welche Werte für "Website-Type"? → Einrichtung, Forschungsgruppe, Kooperation, Projekt, Studiengang, Sonstiges
- [x] Welche Werte für "Nach Laufzeitende"? → Archiv, Löschen, Verlängern
- [x] Organization Unit: Freitext oder Select? → Freitext

### API-Design
- [x] Authentifizierungsmethode: API-Key, OAuth, HMAC? → **API-Key (Bearer Token)**
- [x] Fehlerbehandlung: Retry-Logik bei Netzwerkfehlern? → **Exception mit Fehlermeldung, kein Auto-Retry**
- [x] Asynchrone Verarbeitung oder synchroner Call? → **Synchroner Call**

### Ladestatus
- [x] SSE oder Polling bevorzugt? → **Polling (500ms Intervall)**
- [x] Soll bei Fehler abgebrochen oder fortgefahren werden? → **Abbruch bei Fehler, Retry-Button anzeigen**

---

## Technische Details der bestehenden Implementierung

### InstallationConfig (DTO)
```php
readonly class InstallationConfig
{
    // User-supplied
    public SetupType $type;
    public string $navName;
    public string $domain;
    public string $wid;
    public string $parentOu;
    public string $department;
    public string $siteNameDe;
    public string $siteNameEn;
    // ... weitere Felder

    // Runtime (von Strategies gesetzt)
    public int $targetPid;
    public string $uploadPath;
    public string $slugName;
}
```

### InstallerService Workflow
```
install(InstallationConfig)
├── 1. Validierung: isSetupAllowed()
├── 2. Strategy-Auswahl basierend auf SetupType
├── 3. strategy->prepare() → targetPid, uploadPath setzen
├── 4. FolderService->createStructure()
├── 5. YamlLoader->loadAndMerge()
├── 6. DbImporter->import()
├── 7. SiteConfigService->generate()
└── 8. strategy->postProcess()
```

### Datenbankoperationen
Tabellen die befüllt werden:
- `pages` - Seitenbaum
- `tt_content` - Inhaltselemente
- `sys_template` - TypoScript
- `be_users` - Backend-Benutzer
- `be_groups` - Backend-Gruppen
- `sys_filemounts` - Datei-Mounts
- `sys_category` - Kategorien

---

## Changelog

### Stand: 2026-02-02 (Update: Test-Qualität verbessert)

**Änderungen:**
- PHPUnit XML-Konfiguration auf neues Schema migriert (PHPUnit 12.x)
- Unit-Tests refactored: `createStub()` statt `createMock()` wo keine Expectations benötigt
- 27 PHPUnit Notices behoben (Mock-Objekte ohne Expectations)
- Alle 33 Tests bestehen ohne Warnings/Notices

### Stand: 2026-01-30 (Update: Implementierung abgeschlossen)

**Neu erstellte Dateien:**
- `Classes/Controller/AjaxController.php` - AJAX-Endpoints für Progress + Execute
- `Classes/Service/ProgressTrackingService.php` - Progress-Status via Dateisystem
- `Classes/Service/WebinfoApiService.php` - HTTP-Client für Webinfo-API
- `Classes/Domain/Model/WebinfoData.php` - DTO für Schritt-2-Daten
- `Configuration/Backend/AjaxRoutes.php` - AJAX-Route Registrierung
- `Resources/Private/Templates/BackendInstaller/Step2.html` - Schritt-2 Template
- `Resources/Public/JavaScript/InstallerProgress.js` - Frontend Polling-Logik

**Geänderte Dateien:**
- `Classes/Controller/BackendInstallerController.php` - Neue Actions: step2, submitWebinfo, skipWebinfo
- `Classes/Service/InstallerService.php` - Progress-Callback Parameter hinzugefügt
- `Configuration/Backend/Modules.php` - Neue Actions registriert
- `Resources/Private/Templates/BackendInstaller/Index.html` - Progress-Container, JS-Integration
- `ext_conf_template.txt` - webinfoApiUrl, webinfoApiKey, webinfoApiEnabled Felder

**Funktionen implementiert:**
- [x] Mehrstufiges Formular (Schritt 1 + Schritt 2)
- [x] Live-Fortschrittsanzeige mit Polling
- [x] Session-basiertes State-Management zwischen Schritten
- [x] Webinfo API-Client mit Domain-Validierung (nur *.tum.de)
- [x] API-Key Authentifizierung (Bearer Token)
- [x] Überspringen-Option für Webinfo

---

## Konfiguration

### Extension Settings (ext_conf_template.txt)
```
# Webinfo API
webinfoApiUrl = https://webinfo.tum.de/api/v1/installation
webinfoApiKey = <API_KEY>
webinfoApiEnabled = 1
```

### Oder in additional.php
```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['installer']['webinfoApiUrl'] = 'https://...';
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['installer']['webinfoApiKey'] = 'SECRET';
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['installer']['webinfoApiEnabled'] = true;
```

---

## Nächste Schritte (TODO)

- [x] tum/webinfo Extension: API-Endpoint implementieren
- [x] API-Endpoint: Domain-Whitelist prüfen (Server-seitig)
- [x] API-Endpoint: Daten in Webinfo-Datenbank speichern
- [x] Backend-Modul mit Übersicht und Suchfunktion
- [x] Unit-Tests geschrieben (33 Tests, alle bestanden, keine PHPUnit Notices/Warnings)
- [ ] Testen: Vollständiger Durchlauf Schritt 1 → Schritt 2 → API (manueller Test im Browser)

---

## tum/webinfo Extension

### Struktur
```
packages/webinfo/
├── Classes/
│   ├── Controller/
│   │   ├── ApiController.php           # API-Endpoints (create, list, get)
│   │   └── BackendWebinfoController.php # Backend-Modul
│   ├── Domain/Model/Website.php        # Entity
│   └── Service/
│       ├── ApiAuthenticationService.php # API-Key + Domain Validation
│       └── WebsiteService.php          # CRUD Operations
├── Configuration/
│   ├── Backend/
│   │   ├── AjaxRoutes.php              # API Routes
│   │   └── Modules.php                 # Backend Module
│   ├── TCA/tx_webinfo_domain_model_website.php
│   ├── Icons.php
│   └── Services.yaml
├── Resources/Private/Templates/BackendWebinfo/
│   ├── Index.html                      # Übersicht mit Suche
│   └── Detail.html                     # Detailansicht
├── Tests/Unit/                         # Unit Tests
├── ext_emconf.php
├── ext_conf_template.txt               # apiEnabled, apiKey, allowedDomains
└── ext_tables.sql                      # tx_webinfo_domain_model_website
```

### API-Endpoints
- `POST /typo3/ajax/webinfo_api_create` - Neuen Eintrag erstellen
- `GET /typo3/ajax/webinfo_api_list` - Alle Einträge auflisten (mit Suche)
- `GET /typo3/ajax/webinfo_api_get?uid=X` - Einzelnen Eintrag abrufen

### Backend-Modul Features
- Tabellenübersicht aller registrierten Websites
- Globale Suchfunktion über alle Felder
- Pagination (50 Einträge pro Seite)
- Detailansicht pro Website
- Links zu den Websites

---

## Tests

### PHPUnit Konfiguration
- `phpunit.xml` im Root-Verzeichnis
- PHPUnit 12.5 (mit Attribute-Syntax: `#[Test]`, `#[DataProvider]`)

### Test-Struktur
```
packages/
├── installer/Tests/Unit/Service/
│   ├── ProgressTrackingServiceTest.php   # 6 Tests
│   └── WebinfoApiServiceTest.php         # 6 Tests
└── webinfo/Tests/Unit/
    ├── Controller/ApiControllerTest.php  # 8 Tests
    └── Service/ApiAuthenticationServiceTest.php  # 13 Tests
```

### Test-Ausführung
```bash
# Innerhalb DDEV
ddev exec vendor/bin/phpunit --no-coverage

# Ergebnis: 33 Tests, alle bestanden
```

### Getestete Funktionalitäten
- **ProgressTrackingService:** Init, Update, Complete, Error-Handling
- **WebinfoApiService:** Domain-Validierung (*.tum.de), API-Konfiguration
- **ApiController:** Auth-Fehler (401), Domain-Fehler (403), Validation (400), Erfolg (201)
- **ApiAuthenticationService:** API-Key Validierung, Domain-Pattern-Matching

---

## Referenzen

### Wichtige Dateien
- Controller: `Classes/Controller/BackendInstallerController.php`
- AJAX Controller: `Classes/Controller/AjaxController.php`
- Template Schritt 1: `Resources/Private/Templates/BackendInstaller/Index.html`
- Template Schritt 2: `Resources/Private/Templates/BackendInstaller/Step2.html`
- Hauptservice: `Classes/Service/InstallerService.php`
- Progress Service: `Classes/Service/ProgressTrackingService.php`
- Webinfo Service: `Classes/Service/WebinfoApiService.php`
- Config-Modell: `Classes/Domain/Model/InstallationConfig.php`
- Webinfo-Modell: `Classes/Domain/Model/WebinfoData.php`
- Services.yaml: `Configuration/Services.yaml`
- AJAX Routes: `Configuration/Backend/AjaxRoutes.php`
