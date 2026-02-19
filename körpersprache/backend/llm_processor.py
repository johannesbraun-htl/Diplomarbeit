import json
from openai import OpenAI
from config import settings
from models import AnalysisResult, KeyPoints, ErrorInfo
from datetime import datetime
from pydantic import ValidationError

class LLMProcessor:
    def __init__(self):
        self.client = OpenAI(api_key=settings.OPENAI_API_KEY)
    
    def generate_analysis(self, job_id: str, keypoints: KeyPoints) -> AnalysisResult:
        """Generiert präzise, widerspruchsfreie Analyse via ChatGPT"""
        
        prompt = self._build_prompt(keypoints)
        
        try:
            response = self.client.chat.completions.create(
                model="gpt-4o-mini",
                messages=[
                    {
                        "role": "system",
                        "content": self._get_system_prompt()
                    },
                    {
                        "role": "user",
                        "content": prompt
                    }
                ],
                temperature=0.2,
                max_tokens=1500
            )
            
            raw_json = response.choices[0].message.content.strip()
            
            # JSON parsen & validieren
            try:
                # Entferne mögliche Markdown-Formatierung
                raw_json = raw_json.replace("```json", "").replace("```", "").strip()
                
                result_data = json.loads(raw_json)
                
                # WICHTIG: Konvertiere Scores zu Integer (runde Float-Werte)
                if "overall_score" in result_data:
                    result_data["overall_score"] = int(round(float(result_data["overall_score"])))
                    # Clamp auf 0-100
                    result_data["overall_score"] = max(0, min(100, result_data["overall_score"]))
                
                if "audience_gaze_percent" in result_data:
                    result_data["audience_gaze_percent"] = int(round(float(result_data["audience_gaze_percent"])))
                    # Clamp auf 0-100
                    result_data["audience_gaze_percent"] = max(0, min(100, result_data["audience_gaze_percent"]))
                
                result_data["job_id"] = job_id
                result_data["created_at"] = datetime.utcnow().isoformat()
                result_data["keypoints_used"] = keypoints.model_dump()
                
                # Validiere dass keine Widersprüche existieren
                result_data = self._validate_consistency(result_data, keypoints.metrics)
                
                return AnalysisResult(**result_data)
            
            except (json.JSONDecodeError, ValidationError) as e:
                print(f"JSON Parse Error: {e}")
                print(f"Raw response: {raw_json[:500]}")
                return self._create_fallback_result(job_id, keypoints, str(e))
        
        except Exception as e:
            print(f"LLM Error: {e}")
            return self._create_fallback_result(job_id, keypoints, f"LLM-Fehler: {str(e)}")
    
    def _get_system_prompt(self) -> str:
        """System-Prompt für konsistente, präzise Antworten"""
        return """Du bist ein professioneller Coach für Präsentationstechnik mit Fokus auf Körpersprache.

DEINE AUFGABE:
- Gib KONKRETE, UMSETZBARE Tipps
- Sei PRÄZISE und WIDERSPRUCHSFREI
- Vermeide generische Floskeln
- Fokussiere auf MESSBARE Aspekte (Blickkontakt, Haltung, Bewegung)

VERBOTEN:
- Widersprüchliche Aussagen (z.B. "zu viel Bewegung" UND "mehr Dynamik")
- Allgemeine Tipps ohne Bezug zu den Daten
- Wiederholungen zwischen positiven/negativen Punkten
- Aussagen über Inhalt, Technik oder Umgebung

STIL:
- Direkt und klar
- Ohne Füllwörter
- Konstruktiv, nicht entmutigend
- Professionell aber nicht steif

WICHTIG: overall_score und audience_gaze_percent müssen GANZZAHLEN sein (z.B. 67, nicht 67.5)!

Antworte NUR mit validem JSON, kein Text davor oder danach."""
    
    def _build_prompt(self, keypoints: KeyPoints) -> str:
        """Baut präzisen Prompt mit klaren Anweisungen"""
        
        metrics = keypoints.metrics
        
        # Extrahiere wichtige Metriken
        gaze_pct = metrics.get("audience_gaze_percent", 50)
        posture = metrics.get("avg_posture_score", 0.5)
        movement = metrics.get("avg_movement", 5.0)
        gesture = metrics.get("avg_gesture_activity", 0.3)
        confidence = metrics.get("avg_confidence", 0.5)
        duration = metrics.get("duration_sec", 0)
        
        prompt = f"""Analysiere diese Präsentation basierend auf EXAKTEN Messdaten:

GEMESSENE DATEN:
- Dauer: {duration} Sekunden
- Blickkontakt zum Publikum: {gaze_pct}% der Zeit
- Erkennungs-Sicherheit: {int(confidence * 100)}%
- Körperhaltung-Score: {posture:.2f} (0-1 Skala)
- Durchschnittliche Bewegung: {movement:.2f}
- Gestik-Aktivität: {gesture:.2f}

BEWERTUNGS-REGELN:

1) OVERALL SCORE (0-100) - MUSS GANZZAHL SEIN:
   Berechne: {gaze_pct} * 0.4 + {posture * 100} * 0.3 + [Bewegungs-Score] * 0.15 + [Gestik-Score] * 0.15
   WICHTIG: Runde das Ergebnis auf eine GANZZAHL (z.B. 67, NICHT 67.5)!
   
   Bewegungs-Score:
   - Sehr niedrig (<3): 10 Punkte (zu statisch)
   - Ideal (3-8): 15 Punkte (kontrolliert)
   - Hoch (8-12): 10 Punkte (leicht unruhig)
   - Sehr hoch (>12): 5 Punkte (nervös)
   
   Gestik-Score:
   - Sehr niedrig (<0.2): 10 Punkte (zu steif)
   - Ideal (0.2-0.5): 15 Punkte (angemessen)
   - Hoch (>0.5): 10 Punkte (übertrieben)

2) POSITIVE PUNKTE (3-5 Stück):
   NUR erwähnen wenn WIRKLICH gut (über Durchschnitt):
   - Blickkontakt >70%: "Exzellenter Publikumskontakt"
   - Blickkontakt 50-70%: "Guter Publikumskontakt"
   - Posture >0.7: "Stabile, aufrechte Körperhaltung"
   - Bewegung 4-8: "Kontrollierte, angemessene Bewegungen"
   - Gestik 0.3-0.5: "Unterstützende Gestik"
   
   WICHTIG: Nur ECHTE Stärken nennen!

3) NEGATIVE PUNKTE (2-4 Stück):
   NUR echte Schwächen:
   - Blickkontakt <40%: "Zu wenig direkter Publikumskontakt"
   - Posture <0.5: "Unsichere oder zusammengesackte Haltung"
   - Bewegung >12: "Zu unruhig, lenkt ab"
   - Bewegung <3: "Zu statisch, wirkt steif"
   - Gestik <0.15: "Kaum Gestik, monoton"
   - Gestik >0.6: "Übertriebene Gestik"
   
   WICHTIG: Nicht erfinden! Nur was messbar schlecht ist.

4) VERBESSERUNGSVORSCHLÄGE (3-5 Stück):
   KONKRET und UMSETZBAR basierend auf Schwächen:
   
   Wenn Blickkontakt <50%:
   - "Blick bewusst 2-3 Sekunden bei einzelnen Personen halten"
   - "Raum systematisch abschauen: links → mitte → rechts"
   
   Wenn Bewegung >10:
   - "Füße schulterbreit fixieren, nur obere Körperhälfte bewegen"
   - "Bewusst 3 Sekunden Pause zwischen Gesten einlegen"
   
   Wenn Bewegung <4:
   - "Gezielt 2-3 Schritte bei Themenwechsel machen"
   - "Hände aktiv zur Betonung einsetzen"
   
   Wenn Gestik extrem:
   - Bei zu wenig: "Offene Handflächen bei wichtigen Punkten zeigen"
   - Bei zu viel: "Hände zwischen Gesten in Ruheposition (z.B. locker vor Körper)"

VALIDIERUNG:
- Stelle sicher: Positive und negative Punkte widersprechen sich NICHT
- Wenn Blickkontakt GUT ist: NICHT in negativ erwähnen
- Wenn Bewegung in Ordnung: NICHT in positiv UND negativ

Antworte mit diesem EXAKTEN JSON-Format:
{{
  "overall_score": [GANZZAHL zwischen 0-100, z.B. 67],
  "audience_gaze_percent": {gaze_pct},
  "positive_points": ["konkret1", "konkret2", "konkret3"],
  "negative_points": ["konkret1", "konkret2"],
  "improvement_suggestions": ["umsetzbar1", "umsetzbar2", "umsetzbar3"]
}}"""
        
        return prompt
    
    def _validate_consistency(self, result_data: dict, metrics: dict) -> dict:
        """Prüft und behebt Widersprüche in der Analyse"""
        
        positive = set([p.lower() for p in result_data.get("positive_points", [])])
        negative = set([n.lower() for n in result_data.get("negative_points", [])])
        
        # Entferne Widersprüche
        contradictions = [
            ("blick", "publikum"),
            ("bewegung", "ruhe"),
            ("gestik", "hand"),
            ("haltung", "körper")
        ]
        
        for keyword1, keyword2 in contradictions:
            pos_has = any(keyword1 in p or keyword2 in p for p in positive)
            neg_has = any(keyword1 in n or keyword2 in n for n in negative)
            
            if pos_has and neg_has:
                gaze_pct = metrics.get("audience_gaze_percent", 50)
                
                if "blick" in keyword1 or "publikum" in keyword2:
                    if gaze_pct >= 50:
                        result_data["negative_points"] = [
                            n for n in result_data["negative_points"] 
                            if keyword1 not in n.lower() and keyword2 not in n.lower()
                        ]
                    else:
                        result_data["positive_points"] = [
                            p for p in result_data["positive_points"] 
                            if keyword1 not in p.lower() and keyword2 not in p.lower()
                        ]
        
        # Stelle sicher dass audience_gaze_percent korrekt übernommen wurde
        result_data["audience_gaze_percent"] = metrics.get("audience_gaze_percent", 50)
        
        # Limitiere Anzahl
        result_data["positive_points"] = result_data.get("positive_points", [])[:5]
        result_data["negative_points"] = result_data.get("negative_points", [])[:4]
        result_data["improvement_suggestions"] = result_data.get("improvement_suggestions", [])[:5]
        
        return result_data
    
    def _create_fallback_result(self, job_id: str, keypoints: KeyPoints, error_msg: str) -> AnalysisResult:
        """Erstellt intelligentes Fallback-Ergebnis"""
        
        metrics = keypoints.metrics
        gaze_pct = metrics.get("audience_gaze_percent", 50)
        posture = metrics.get("avg_posture_score", 0.5)
        movement = metrics.get("avg_movement", 5.0)
        gesture = metrics.get("avg_gesture_activity", 0.3)
        
        # Score berechnen
        score_gaze = gaze_pct * 0.4
        score_posture = posture * 100 * 0.3
        
        if movement < 3:
            score_movement = 10 * 0.15
        elif movement < 8:
            score_movement = 15 * 0.15
        elif movement < 12:
            score_movement = 10 * 0.15
        else:
            score_movement = 5 * 0.15
        
        if gesture < 0.2:
            score_gesture = 10 * 0.15
        elif gesture < 0.5:
            score_gesture = 15 * 0.15
        else:
            score_gesture = 10 * 0.15
        
        score = int(round(score_gaze + score_posture + score_movement + score_gesture))
        score = max(0, min(100, score))
        
        # Generiere Punkte basierend auf Daten
        positive = []
        negative = []
        suggestions = []
        
        # Blickkontakt
        if gaze_pct >= 70:
            positive.append(f"Exzellenter Publikumskontakt ({gaze_pct}%)")
        elif gaze_pct >= 50:
            positive.append(f"Guter Publikumskontakt ({gaze_pct}%)")
        elif gaze_pct < 40:
            negative.append(f"Zu wenig Publikumskontakt ({gaze_pct}%)")
            suggestions.append("Blick bewusst 2-3 Sekunden bei einzelnen Personen halten")
        
        # Haltung
        if posture >= 0.7:
            positive.append("Stabile, selbstbewusste Körperhaltung")
        elif posture < 0.5:
            negative.append("Körperhaltung wirkt unsicher")
            suggestions.append("Bewusst aufrecht stehen: Schultern zurück, Brust raus")
        
        # Bewegung
        if 4 <= movement <= 8:
            positive.append("Kontrollierte, angemessene Bewegungen")
        elif movement > 12:
            negative.append("Zu viel Bewegung, wirkt unruhig")
            suggestions.append("Füße schulterbreit fixieren, bewusste Pausen einlegen")
        elif movement < 3:
            negative.append("Zu statisch, zu wenig Dynamik")
            suggestions.append("Gezielt 2-3 Schritte bei Themenwechsel")
        
        # Gestik
        if 0.3 <= gesture <= 0.5:
            positive.append("Unterstützende Gestik vorhanden")
        elif gesture < 0.2:
            negative.append("Kaum Gestik, wirkt monoton")
            suggestions.append("Hände aktiv zur Betonung wichtiger Punkte nutzen")
        elif gesture > 0.6:
            negative.append("Übertriebene Gestik lenkt ab")
            suggestions.append("Gesten reduzieren, Hände in Ruheposition zwischen wichtigen Punkten")
        
        # Generische Tipps nur wenn spezifische fehlen
        if len(suggestions) < 3:
            suggestions.append("Vor Spiegel oder mit Video üben")
        
        # Fallback-Werte falls leer
        if not positive:
            positive.append("Präsentation wurde vollständig analysiert")
        if not negative:
            negative.append("Keine gravierenden Schwächen erkannt")
        if not suggestions:
            suggestions.append("Weiter üben für mehr Sicherheit")
        
        return AnalysisResult(
            job_id=job_id,
            created_at=datetime.utcnow().isoformat(),
            overall_score=score,
            audience_gaze_percent=gaze_pct,
            positive_points=positive,
            negative_points=negative,
            improvement_suggestions=suggestions,
            keypoints_used=keypoints,
            errors=[ErrorInfo(stage="llm", message=f"Fallback verwendet: {error_msg}")]
        )

llm_processor = LLMProcessor()