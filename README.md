# Diplomarbeit 2025/26 HTL Rankweil Informatik
# KI-gestützte Analyse von Präsentationen

![Status](https://img.shields.io/badge/status-in%20progress-yellow) 
![Made%20with-Python](https://img.shields.io/badge/Made%20with-Python-green) 
![License](https://img.shields.io/badge/license-Educational-lightgrey)

---

## Projektbeschreibung
Diplomarbeit 2025/26 von **Johannes Braun** und **Felix Ilmer**  
Dieses Projekt analysiert Vorträge und Präsentationen anhand von **Sprache** und **Körpersprache** und gibt dem Vortragenden objektives Feedback.

---

## Ziele
- **Sprache**
  - Erkennung von Füllwörtern  
  - Analyse von Sprechtempo  
  - Bewertung von Pausen und Lautstärke  

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
- **Programmiersprachen:** Python, *[weitere falls benötigt]*  
- **Frameworks / Libraries:** *[z. B. für Sprache, Bildverarbeitung, GUI]*  
- **Projektmanagement:** GitHub Projects (Kanban), GitHub Repository, *[weitere Tools falls nötig]*  
- **Diagramme & Dokumentation:** UML, ERM, Sequenzdiagramme  

---

## Projektorganisation
- **Teammitglieder**  
  - Johannes Braun – Schwerpunkt Körpersprache  
  - Felix Ilmer – Schwerpunkt Sprache  

- **Gesamtaufwand**: 660 Stunden (2 × 330 Stunden)  

---

## Installation & Nutzung (sobald satuts ferig)
1. Repository klonen  
   ```bash
   git clone <repo-url>
   cd diplomarbeit-ki-analyse

2. Virtuelle Umgebung erstellen & Abhängigkeiten installieren  
   ```bash
   python -m venv venv
   source venv/bin/activate  # Linux/Mac
   venv\Scripts\activate     # Windows
   pip install -r requirements.txt

3. Anwendung starten  
   ```bash
   python main.py

---

## Tests
- Durchführung mehrerer Beispiel-Präsentationen
- Vergleich: Systemanalyse vs. Beobachtung durch Personen
- Optimierung anhand der Ergebnisse

--- 

## Lizenz
Dieses Projekt ist Teil einer schulischen Diplomarbeit und dient ausschließlich Ausbildungszwecken.
Eine kommerzielle Nutzung ist nicht vorgesehen.