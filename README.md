# Diplomarbeit 2025/26
# PresentAI – KI-gestützte Analyse von Präsentationen

![PresentAI Logo](./docs/logo.png)
![Status](https://img.shields.io/badge/status-in%20progress-yellow) 
![Made%20with-Python](https://img.shields.io/badge/Made%20with-Python-green) 
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)

## Projektbeschreibung
Diplomarbeit 2025/26 von **Johannes Braun** und **Felix Ilmer**  
**PresentAI** analysiert Vorträge und Präsentationen anhand von **Sprache** und **Körpersprache** und gibt dem Vortragenden objektives Feedback.

---

## Ziele
- **Sprache**
  - Erkennung von Füllwörtern  
  - Analyse von Sprechtempo  

- **Körpersprache**
  - Erkennung von Gestik und Haltung  
  - Analyse von Kopf- und Blickrichtung  
  - Bewertung der Bewegungshäufigkeit  

- **Ausgabe**
  - Übersichtliches Feedback-Dashboard  
  - Möglichkeit zum Export der Ergebnisse *(Nice to Have)*  

---

## Systemübersicht
**Input**  
- Kamera  
- Mikrofon  

**Verarbeitung**  
- Modul Sprache  
- Modul Körpersprache  

**Output**  
- Oberfläche mit Diagrammen, Scores und Hinweisen  

---

## Technologien
- **Web-Stack:** PHP 8, MySQL (z. B. MariaDB), HTML/CSS/Vanilla JS
- **Backend-Logik:** PHP-Session-Handling für Login/Registrierung, CRUD für Präsentationen über Prepared Statements
- **Prototyp Sprach-Analyse:** Python 3 (Flask) + OpenAI Whisper, Librosa/SoundFile für Audiobearbeitung, einfache Wort- und Tempobewertung
- **Frontend-Assets:** Plain CSS, Tabs und Formular-Logik über leichte JS-Skripte (keine Build-Tools)

## Architektur / Funktionsumfang (aktueller Stand)
- **Auth-Flow:** Startseite mit Login/Registrierung (`Code/index.php`) speichert Sessions und leitet nach erfolgreichem Login auf die Hauptseite weiter.
- **Hauptseite:** Tab-basiertes UI (`Code/Frontend/main.php`) mit Bereichen *Home* (Präsentationen anlegen/auflisten), *Analyse* und *Einstellungen*.
- **Präsentationsverwaltung:** Upload & Verwaltung via PHP-Handlern (`Code/Backend/php/home/*`), Datenspeicherung in MySQL, Ausgabe als Tabelle mit KPI-Platzhaltern.
- **Sprach-Analyse-Prototyp:** Eigenständiger Flask-Service (`Tests/Felix/whisper_test/backend/app.py`) mit Whisper-Transkription, Füllwort-Erkennung, WPM-Berechnung und Feedback.
- **Datenbank-Anbindung:** Momentan über eine einfache `connect.php` (statisch konfigurierte Credentials); perspektivisch sollte dies auf `.env`/Environment-Variablen umgestellt werden.

## Automatisierte Checks
- Eine GitHub Action (`.github/workflows/daily-check.yml`) läuft täglich um 07:00 UTC und führt aktuell folgende Prüfungen aus:
  - **PHP-Syntax-Check** für alle Dateien unter `Code/` via `php -l`.
  - **Python-Bytecode-Kompilierung** für alle Skripte unter `Tests/` via `python -m compileall`.
  - Die Action lässt sich zusätzlich manuell über *Workflow Dispatch* starten und kann bei Bedarf um weitere Tests erweitert werden.

---

## Projektorganisation
- **Teammitglieder**  
  - Johannes Braun – Schwerpunkt Körpersprache  
  - Felix Ilmer – Schwerpunkt Sprache  

- **Gesamtaufwand**: 660 Stunden (2 × 330 Stunden)

---

## Lizenz
Dieses Projekt ist Teil einer schulischen Diplomarbeit und dient ausschließlich Ausbildungszwecken.
Eine kommerzielle Nutzung ist nicht vorgesehen.
