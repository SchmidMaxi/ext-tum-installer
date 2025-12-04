# TUM Site Installer (`EXT:installer`)

Der **TUM Site Installer** automatisiert das Aufsetzen und Konfigurieren von TYPO3-Webseiten innerhalb der TUM-Infrastruktur. Er erstellt Datenbank-Einträge, Ordnerstrukturen und Site-Configurations basierend auf vordefinierten YAML-Templates.

## Features

* **Setup 1 (Initial):** Erstellt eine komplette Domain-Struktur (Root-Domain + Erstes Projekt) inkl. `sRoot`.
* **Setup 3 (Update/Subsite):** Erstellt weitere Projekte/Unterseiten in einer bestehenden Instanz.
* **Auto-Configuration:** Generiert `config.yaml`, `settings.yaml` und `csp.yaml` mit korrekten Imports aus `EXT:sitetum`.
* **Fileadmin Struktur:** Legt automatisch Ordner an (`/wid/kürzel/_my_direct_uploads`).
* **Conditions:** Unterstützt optionale Features wie News-System oder Intropages via Checkbox/Flag.

## Nutzungsmöglichkeiten

Es gibt drei Wege, eine Installation durchzuführen.

### 1. Über das TYPO3 Backend

1.  Logge dich ins Backend ein.
2.  Gehe im Modul-Menü links auf **System** -> **TUM Installer**.
3.  Fülle das Formular aus:
    * **Setup:** Wähle "Setup 1" für leere Instanzen, sonst "Setup 3".
    * **NavName:** Das Kürzel des Projekts (z.B. `tum6`).
    * **Domain:** Die volle Domain (z.B. `tum6.typo3.tum.de`).
    * **WID & LRZ-ID:** Metadaten für die Verwaltung.
    * **Features:** Wähle benötigte Erweiterungen (News, Intropage etc.).
4.  Klicke auf "Installation starten".

### 2. Über die Konsole auf dem Server

Der Command `tum:installer:run` ist der schnellste Weg für Entwickler.

**Syntax:**
```bash
./vendor/bin/typo3 tum:installer:run <SetupName> [Optionen]
```

### 3. Über die Konsole (CLI) in DDEV

**Syntax:**
```bash
ddev exec typo3 tum:installer:run <SetupName> [Optionen]
```

## Best Practices & Beispiele

Hier sind die gängigsten Szenarien als Copy-Paste Vorlagen.

### Szenario A: Initiale Installation (Setup 1)

*Erstellt sRoot, Domain-Ebene (config-d) und das erste Projekt.*

**Beispiel:** Neue Seite "TUM Test" (`tumtest`) auf WID `w123`.

```bash
ddev exec typo3 tum:installer:run Setup1 \
  --nav-name="tumtest" \
  --domain="tumtest.typo3.tum.de" \
  --site-name-de="TUM Testumgebung" \
  --site-name-en="TUM Test Environment" \
  --wid="w123" \
  --lrz-id="lu00test" \
  --parent-ou="CIT" \
  --news \
  --intropage
```

### Szenario B: Weiteres Projekt hinzufügen (Setup 3)

*Fügt einem bestehenden System ein neues Projekt hinzu. Die Domain-Ebene wird ignoriert.*

**Beispiel:** Neuer Lehrstuhl "Botanik" (`botanik`) im gleichen System.

```bash
ddev exec typo3 tum:installer:run Setup3 \
  --nav-name="botanik" \
  --domain="tumtest.typo3.tum.de" \
  --site-name-de="Lehrstuhl für Botanik" \
  --wid="w999" \
  --imprint="Musterstraße 1\n80333 München" \
  --member-list
```

-----

## Verfügbare Optionen (Flags)

| Option | Beschreibung |
| :--- | :--- |
| `setup` | **Pflicht-Argument.** `Setup1` oder `Setup3`. |
| `--nav-name` | Das Projekt-Kürzel (dient als Ordnername und ID). |
| `--domain` | Die Domain (für Site Config Base URL). |
| `--wid` | W-Kennung der Einrichtung. |
| `--lrz-id` | LRZ-Kennung / Satellite ID. |
| `--site-name-de` | Titel der Website (Deutsch). |
| `--site-name-en` | Titel der Website (Englisch). |
| `--parent-ou` | Kürzel der übergeordneten Einheit (z.B. CIT, ED). |
| `--imprint` | Text für den Impressum-Kontakt im Footer. |
| `--accessibility` | Text für den Barrierefreiheits-Kontakt. |
| `--news` | Installiert News-Ordner, Seiten und Konfiguration. |
| `--intropage` | Erstellt Intronav-Ordner und setzt TSConfig. |
| `--curl-content` | Aktiviert CurlContent Logik. |
| `--member-list` | Aktiviert MemberList Logik. |
| `--courses` | Aktiviert TUM Courses Logik. |
| `--vcard` | Aktiviert TUM vCard Logik. |

## Technische Struktur

* **Configuration/Installer/**: Hier liegen die YAML-Blueprints (`Setup1.yaml`, `WebPages.yaml`, etc.).
* **Classes/Service/SetupService.php**: Die Hauptlogik. Steuert DB-Import, Ordner-Erstellung und Site-Config.
* **Classes/Service/DataProcessingService.php**: Verarbeitet Platzhalter (`{$navName}`) und Conditions in den YAML-Dateien.
