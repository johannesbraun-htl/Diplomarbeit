#!/usr/bin/env python3
from flask import Flask, request, jsonify
from flask_cors import CORS
import whisper
import tempfile
import os
import base64
import numpy as np
import librosa
import soundfile as sf
import re
from collections import Counter

app = Flask(__name__)
CORS(app)

# -----------------------
# Modell laden (Whisper)
# -----------------------
print("Lade Whisper Modell (small)...")
try:
    model = whisper.load_model("small")
    print("✅ Whisper Modell geladen")
except Exception as e:
    print("⚠️ Fehler beim Laden des Whisper-Modells:", e)
    model = None

# -----------------------
# Füllwortliste
# -----------------------
FILLER_WORDS = {
    "ähm": {"variations": ["ähm"], "weight": 2.0},
    "äh": {"variations": ["äh", "eh"], "weight": 1.8},
    "also": {"variations": ["also"], "weight": 1.5},
    "sozusagen": {"variations": ["sozusagen"], "weight": 1.3},
    "quasi": {"variations": ["quasi"], "weight": 1.3},
    "eigentlich": {"variations": ["eigentlich"], "weight": 1.2},
    "halt": {"variations": ["halt"], "weight": 1.2},
    "okay": {"variations": ["okay", "ok"], "weight": 1.1},
    "genau": {"variations": ["genau"], "weight": 1.1},
    "einfach": {"variations": ["einfach"], "weight": 1.1},
    "praktisch": {"variations": ["praktisch"], "weight": 1.0},
    "wirklich": {"variations": ["wirklich"], "weight": 1.0},
    "übrigens": {"variations": ["übrigens"], "weight": 1.0},
    "kurz": {"variations": ["kurz"], "weight": 0.9},
    "klar": {"variations": ["klar"], "weight": 0.9},
    "sowieso": {"variations": ["sowieso"], "weight": 0.9},
    "allgemein": {"variations": ["allgemein"], "weight": 0.8}
}

# -----------------------
# Hilfsfunktionen
# -----------------------
def improve_audio_quality(audio_path: str) -> str:
    """
    Versucht Audio zu verbessern / nach WAV zu konvertieren.
    Falls Fehler auftreten, wird der original path zurückgegeben.
    """
    try:
        y, sr = librosa.load(audio_path, sr=16000)
        # Preemphasis (einfacher Hochpass)
        try:
            y_cleaned = librosa.effects.preemphasis(y)
        except Exception:
            y_cleaned = y
        # RMS-Normalisierung (sicher)
        if y_cleaned.size:
            rms = np.sqrt(np.mean(y_cleaned**2))
        else:
            rms = 0.0
        y_normalized = y_cleaned * (0.1 / rms) if rms > 0 else y_cleaned
        improved_path = os.path.splitext(audio_path)[0] + "_improved.wav"
        sf.write(improved_path, y_normalized, sr)
        return improved_path
    except Exception as e:
        print("Audio-Verbesserung fehlgeschlagen:", e)
        return audio_path

def get_audio_duration(audio_path: str) -> float:
    try:
        return float(librosa.get_duration(filename=audio_path))
    except Exception as e:
        print("Fehler beim Ermitteln der Audio-Dauer:", e)
        return 0.0

def calculate_words_per_minute(transcription: str, audio_duration: float) -> float:
    if not transcription or audio_duration <= 0:
        return 0.0
    words = transcription.split()
    wpm = (len(words) / audio_duration) * 60.0
    return round(wpm, 2)

def analyze_speech_tempo(wpm: float) -> dict:
    if wpm == 0:
        return {"rating": "unbekannt", "feedback": "Keine Sprache erkannt", "ideal_range": "120-150 WPM", "color": "#6c757d"}
    if wpm < 100:
        return {"rating": "zu_langsam", "feedback": f"Ihr Sprechtempo ist mit {wpm} WPM etwas zu langsam.", "ideal_range": "120-150 WPM", "color": "#dc3545"}
    if wpm <= 120:
        return {"rating": "etwas_langsam", "feedback": f"Ihr Sprechtempo ist mit {wpm} WPM etwas langsam, aber akzeptabel.", "ideal_range": "120-150 WPM", "color": "#fd7e14"}
    if wpm <= 150:
        return {"rating": "optimal", "feedback": f"Perfektes Sprechtempo! {wpm} WPM ist ideal für Präsentationen.", "ideal_range": "120-150 WPM", "color": "#28a745"}
    if wpm <= 180:
        return {"rating": "etwas_schnell", "feedback": f"Ihr Sprechtempo ist mit {wpm} WPM etwas schnell.", "ideal_range": "120-150 WPM", "color": "#fd7e14"}
    return {"rating": "zu_schnell", "feedback": f"Ihr Sprechtempo ist mit {wpm} WPM zu schnell.", "ideal_range": "120-150 WPM", "color": "#dc3545"}

def detect_filler_words(text: str) -> dict:
    if not text or len(text.strip()) < 2:
        return {
            "marked_text": text or "",
            "clean_text": text or "",
            "filler_count": {},
            "total_fillers": 0,
            "filler_percentage": 0.0,
            "filler_score": 0.0,
            "total_words": 0,
            "detected_fillers": []
        }

    words = re.findall(r'\b\w+\b', text.lower())
    total_words = len(words)

    matches = []
    for filler, info in FILLER_WORDS.items():
        variations = set(info.get("variations", []))
        for variation in variations:
            pattern = re.compile(r'\b' + re.escape(variation) + r'\b', re.IGNORECASE)
            for m in pattern.finditer(text):
                matches.append({
                    "start": m.start(),
                    "end": m.end(),
                    "filler": filler,
                    "original": m.group(0),
                    "weight": info.get("weight", 1.0)
                })

    # Keine Treffer
    if not matches:
        return {
            "marked_text": text,
            "clean_text": text,
            "filler_count": {},
            "total_fillers": 0,
            "filler_percentage": 0.0,
            "filler_score": 0.0,
            "total_words": total_words,
            "detected_fillers": []
        }

    # Einfügungen von rechts nach links
    matches.sort(key=lambda x: x["start"], reverse=True)
    marked_text = text
    filler_count = Counter()
    detected_fillers = []
    used_spans = []

    for m in matches:
        s, e = m["start"], m["end"]
        if any(not (e <= us or s >= ue) for us, ue in used_spans):
            continue
        original_word = m["original"]
        filler = m["filler"]
        marked_text = marked_text[:s] + f'<mark class="filler-word" data-filler="{filler}">{original_word}</mark>' + marked_text[e:]
        filler_count[filler] += 1
        detected_fillers.append({"word": filler, "original": original_word, "position": s, "weight": m["weight"]})
        used_spans.append((s, e))

    total_fillers = sum(filler_count.values())
    filler_percentage = (total_fillers / total_words * 100) if total_words > 0 else 0.0
    filler_score = sum(filler_count[f] * FILLER_WORDS[f]["weight"] for f in filler_count)

    return {
        "marked_text": marked_text,
        "clean_text": text,
        "filler_count": dict(filler_count),
        "total_fillers": total_fillers,
        "filler_percentage": round(filler_percentage, 2),
        "filler_score": round(filler_score, 2),
        "total_words": total_words,
        "detected_fillers": detected_fillers
    }

def post_process_text(text: str) -> str:
    if not text:
        return ""
    text = text.strip()
    text = ' '.join(text.split())
    text = text.replace(' ,', ',').replace(' .', '.').replace(' ?', '?').replace(' !', '!')
    if len(text) > 1:
        text = text[0].upper() + text[1:]
    return text

# -----------------------
# Routes
# -----------------------
@app.route('/')
def home():
    return jsonify({
        "status": "OK",
        "service": "Whisper Live Transkription",
        "model_loaded": model is not None
    })

@app.route('/transcribe', methods=['POST'])
def transcribe_audio():
    tmp_files = []
    try:
        data = request.get_json(silent=True)
        if not data or "audio_data" not in data:
            return jsonify({"success": False, "error": "Keine Audio-Daten im Request"}), 400

        audio_base64 = data.get("audio_data")
        if not isinstance(audio_base64, str) or ',' not in audio_base64:
            return jsonify({"success": False, "error": "audio_data muss ein Base64-DataURL-String sein"}), 400

        # base64 decode
        try:
            audio_bytes = base64.b64decode(audio_base64.split(",", 1)[1])
        except Exception as e:
            return jsonify({"success": False, "error": f"Base64-Decode fehlgeschlagen: {e}"}), 400

        # temporäre Datei schreiben
        tmp_in = tempfile.NamedTemporaryFile(suffix=".webm", delete=False)
        try:
            tmp_in.write(audio_bytes)
            tmp_in.flush()
            tmp_path = tmp_in.name
        finally:
            tmp_in.close()
        tmp_files.append(tmp_path)

        # optional verbessern / konvertieren
        improved_path = improve_audio_quality(tmp_path)
        if improved_path and os.path.exists(improved_path) and improved_path not in tmp_files:
            tmp_files.append(improved_path)

        # audio duration
        audio_duration = get_audio_duration(improved_path) or 0.0

        # Modell prüfen
        if model is None:
            return jsonify({"success": False, "error": "Whisper-Modell nicht geladen"}), 500

        # Transkription
        try:
            result = model.transcribe(improved_path, language="de", task="transcribe", fp16=False)
        except Exception as e:
            return jsonify({"success": False, "error": f"Transkription fehlgeschlagen: {e}"}), 500

        text = post_process_text(result.get("text", "") or "")
        wpm = calculate_words_per_minute(text, audio_duration)
        tempo = analyze_speech_tempo(wpm)
        filler = detect_filler_words(text)

        # confidence (optional)
        confidence = None
        segs = result.get("segments")
        if isinstance(segs, list) and len(segs) > 0:
            logprobs = [s.get("avg_logprob") for s in segs if s.get("avg_logprob") is not None]
            if logprobs:
                try:
                    confidence = float(np.mean([float(x) for x in logprobs]))
                except Exception:
                    confidence = None

        response = {
            "success": True,
            "clean_text": text,
            "marked_text": filler.get("marked_text", ""),
            "speech_tempo": {
                "words_per_minute": wpm,
                "audio_duration_seconds": round(audio_duration, 2),
                "rating": tempo.get("rating"),
                "feedback": tempo.get("feedback"),
                "ideal_range": tempo.get("ideal_range"),
                "color": tempo.get("color")
            },
            "filler_analysis": {
                "count": filler.get("filler_count", {}),
                "total": filler.get("total_fillers", 0),
                "percentage": filler.get("filler_percentage", 0.0),
                "score": filler.get("filler_score", 0.0),
                "total_words": filler.get("total_words", 0),
                "detected": filler.get("detected_fillers", [])
            },
            "language": result.get("language", "de"),
            "confidence": round(confidence, 2) if confidence is not None else None
        }

        return jsonify(response)

    except Exception as e:
        print("Unhandled transcribe error:", e)
        return jsonify({"success": False, "error": str(e)}), 500

    finally:
        # cleanup temporäre dateien
        for f in tmp_files:
            try:
                if f and os.path.exists(f):
                    os.unlink(f)
            except Exception:
                pass

@app.route('/filler-words', methods=['GET'])
def get_filler_words():
    return jsonify({"filler_words": FILLER_WORDS, "total_configured": len(FILLER_WORDS)})

@app.route('/speech-tempo-info', methods=['GET'])
def get_speech_tempo_info():
    return jsonify({
        "ideal_wpm_range": "120-150 WPM",
        "description": "Wörter pro Minute für optimale Verständlichkeit in Präsentationen"
    })

if __name__ == "__main__":
    print("Starte Flask auf http://localhost:5000")
    app.run(debug=True, port=5000)
