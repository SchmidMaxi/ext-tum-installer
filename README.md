# TUM Webinfo / Installer Project

Dies ist die Entwicklungsumgebung für die `EXT:installer` und das zugehörige TUM Webinfo Management System. Das Projekt basiert auf TYPO3 v14 und DDEV.

## Voraussetzungen

* [Docker Desktop](https://www.docker.com/products/docker-desktop)
* [DDEV](https://ddev.readthedocs.io/en/stable/)

## Installation & Setup

Führe folgende Befehle im Root-Verzeichnis des Projekts aus, um die Umgebung zu starten:

1.  **Umgebung starten**
    ```bash
    ddev start
    ```

2.  **Abhängigkeiten installieren**
    ```bash
    ddev composer install
    ```

3.  **Datenbank importieren**
    Importiert den initialen Datenbank-Dump (Basis-Daten für TYPO3 v14):
    ```bash
    ddev import-db --file=backup.sql.gz
    ```

## Zugriff

* **Frontend:** `https://installer.ddev.site` (oder siehe `ddev describe`)
* **Backend:** `https://installer.ddev.site/typo3`
* **Datenbank (PMA):** `ddev launch -p`
* **BE-User:** tumin

---
*Status: Development / TYPO3 v14.0.1*