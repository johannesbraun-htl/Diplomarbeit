# 🎥 Körpersprache Analyzer mit Timestamp-Events

Vollständiges System zur Analyse von Körpersprache in Präsentations-Videos mit klickbaren Timestamps für wichtige Momente.

## 🎯 Features

- ✅ Chunked Upload bis 3 GB
- ✅ Echtzeit-Fortschritt während Upload
- ✅ Kontinuierliches Frame-by-Frame Tracking
- ✅ Debug-Video mit Visualisierung
- ✅ **Klickbare Timestamps** für wichtige Ereignisse:
  - 🔴 Kritisch: Kein Gesicht erkannt (>3s)
  - 🟠 Warnung: Blick vom Publikum weg (>2s)
  - 🔵 Info: Übermäßige Bewegung
- ✅ ChatGPT-Analyse mit konkreten Tipps

## 📦 Installation

### Backend
```bash
cd backend
pip install -r requirements.txt
```

Erstelle `.env` mit deinem OpenAI Key:
```env
OPENAI_API_KEY=sk-proj-dein-key-hier
```

### Frontend
Kopiere `xampp_frontend/` nach `C:\xampp\htdocs\körpersprache\`

## 🚀 Verwendung

1. Backend: `run_backend.bat`
2. XAMPP starten (Apache)
3. Browser: `http://localhost/körpersprache/`
4. Video hochladen → Analyse → **Timestamps anklicken um zu wichtigen Momenten zu springen**

## 🎯 Timestamp-Events

- **Blick weggeschaut** (Warning): >2 Sekunden kein Publikumskontakt
- **Kein Gesicht** (Critical): >3 Sekunden außerhalb des Bildes
- **Hohe Bewegung** (Info): Übermäßige Aktivität erkannt

**Klicke auf einen Timestamp** → Video springt direkt dorthin!

## 📊 Technologie

- Backend: FastAPI, OpenCV, OpenAI API
- Frontend: Vanilla JavaScript
- CV: Haar Cascade Classifiers + kontinuierliches Tracking