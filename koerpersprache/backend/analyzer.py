import cv2
import numpy as np
from pathlib import Path
from typing import Dict, List, Any, Optional, Tuple
from models import KeyPoints, TimeSegment, TimestampEvent
from collections import deque

class VideoAnalyzer:
    def __init__(self):
        print("ℹ️  VideoAnalyzer mit verbessertem Tracking + Event-Timestamps")
        
        # Mehrere Cascade Classifiers für bessere Erkennung
        self.face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
        self.face_cascade_alt = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_alt2.xml')
        self.eye_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_eye.xml')
        self.eye_cascade_glasses = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_eye_tree_eyeglasses.xml')
        
        # Smoothing Buffer für stabilere Ergebnisse
        self.gaze_buffer = deque(maxlen=5)
        
        # Event-Tracking
        self.timestamp_events = []
    
    def analyze_video(self, video_path: str, output_debug_video: bool = True) -> KeyPoints:
        """Extrahiert Key Points + intelligente Timestamp-Events"""
        
        cap = cv2.VideoCapture(video_path)
        if not cap.isOpened():
            raise ValueError(f"Kann Video nicht öffnen: {video_path}")
        
        fps = cap.get(cv2.CAP_PROP_FPS) or 30
        total_frames = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
        duration_sec = total_frames / fps
        width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
        height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
        
        # Debug-Video Writer
        debug_video_path = None
        out = None
        if output_debug_video:
            debug_video_path = str(Path(video_path).parent / f"debug_{Path(video_path).name}")
            fourcc = cv2.VideoWriter_fourcc(*'mp4v')
            out = cv2.VideoWriter(debug_video_path, fourcc, fps, (width, height))
        
        # JEDEN FRAME analysieren
        sample_interval = 1
        
        # Metriken sammeln
        brightness_samples = []
        motion_samples = []
        gaze_samples = []
        gaze_confidence_samples = []
        face_positions = []
        
        prev_frame_gray = None
        frame_idx = 0
        
        # Tracking-Variablen
        last_face = None
        frames_without_face = 0
        
        # Event-Tracking Variablen
        self.timestamp_events = []
        
        # Für kontinuierliche Phasen
        gaze_away_start = None
        gaze_away_frames = 0
        excellent_gaze_start = None
        excellent_gaze_frames = 0
        
        # Status-Check Intervall (alle 30 Sekunden)
        status_check_interval = 30  # Sekunden
        last_status_check = 0
        
        # Für Phase-Tracking (alle 10 Sekunden Performance-Snapshot)
        phase_interval = 10  # Sekunden
        last_phase_check = 0
        phase_gaze_samples = []
        
        while True:
            ret, frame = cap.read()
            if not ret:
                break
            
            timestamp_sec = frame_idx / fps
            
            # Debug-Frame erstellen
            debug_frame = frame.copy()
            
            # Grayscale für CV
            gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
            
            # Bewegungserkennung (alle 5 Frames)
            if frame_idx % 5 == 0:
                brightness = np.mean(gray)
                brightness_samples.append(brightness)
                
                if prev_frame_gray is not None:
                    diff = cv2.absdiff(gray, prev_frame_gray)
                    motion = np.mean(diff)
                    motion_samples.append(motion)
                    
                    # EVENT: Übermäßige Bewegung
                    if motion > 25:
                        self._add_event(
                            timestamp_sec=timestamp_sec,
                            event_type="excessive_movement",
                            severity="negative",
                            description=f"Sehr starke Bewegung (Wert: {motion:.1f})"
                        )
                
                prev_frame_gray = gray.copy()
            
            # GESICHTS- UND BLICK-ERKENNUNG
            gaze_result, confidence = self._detect_gaze(gray, frame, last_face)
            
            if gaze_result["face_detected"]:
                last_face = gaze_result["face_rect"]
                frames_without_face = 0
                
                # Zeichne Gesicht
                x, y, w, h = gaze_result["face_rect"]
                cv2.rectangle(debug_frame, (x, y), (x+w, y+h), (255, 0, 0), 3)
                
                # Zeichne Augen
                if gaze_result["eyes"]:
                    for (ex, ey, ew, eh) in gaze_result["eyes"]:
                        cv2.rectangle(debug_frame, (x+ex, y+ey), (x+ex+ew, y+ey+eh), (0, 255, 0), 2)
                        eye_center = (x + ex + ew//2, y + ey + eh//2)
                        cv2.circle(debug_frame, eye_center, 4, (0, 255, 255), -1)
                
                # Blickrichtung
                direction = gaze_result["direction"]
                is_audience = gaze_result["is_audience"]
                
                gaze_samples.append("audience" if is_audience else "away")
                gaze_confidence_samples.append(confidence)
                phase_gaze_samples.append("audience" if is_audience else "away")
                
                # EVENT-TRACKING: Negative Events (Blick weg)
                if not is_audience:
                    if gaze_away_start is None:
                        gaze_away_start = timestamp_sec
                    gaze_away_frames += 1
                    
                    # Reset excellent gaze
                    excellent_gaze_start = None
                    excellent_gaze_frames = 0
                    
                    # Nach 3 Sekunden wegschauen = Event
                    if gaze_away_frames == int(fps * 3):
                        self._add_event(
                            timestamp_sec=gaze_away_start,
                            event_type="gaze_away",
                            severity="negative",
                            description=f"Blick vom Publikum weg (ab {self._format_time(gaze_away_start)})"
                        )
                    
                    # Nach 8 Sekunden = kritisches Event
                    elif gaze_away_frames == int(fps * 8):
                        self._add_event(
                            timestamp_sec=gaze_away_start,
                            event_type="gaze_away_long",
                            severity="critical",
                            description=f"Längere Phase ohne Publikumskontakt (8+ Sekunden)"
                        )
                
                else:  # is_audience = True
                    # Reset gaze away tracking
                    if gaze_away_start is not None and gaze_away_frames > 0:
                        gaze_away_start = None
                        gaze_away_frames = 0
                    
                    # EVENT-TRACKING: Positive Events (Exzellenter Blick)
                    if confidence > 0.85 and direction in ["center", "left_slight", "right_slight"]:
                        if excellent_gaze_start is None:
                            excellent_gaze_start = timestamp_sec
                        excellent_gaze_frames += 1
                        
                        # Nach 5 Sekunden exzellentem Blick = Positive Event
                        if excellent_gaze_frames == int(fps * 5):
                            self._add_event(
                                timestamp_sec=excellent_gaze_start,
                                event_type="excellent_gaze",
                                severity="positive",
                                description=f"Exzellenter Publikumskontakt (ab {self._format_time(excellent_gaze_start)})"
                            )
                    else:
                        excellent_gaze_start = None
                        excellent_gaze_frames = 0
                
                # Zeichne Blickrichtungs-Pfeil
                face_center = (x + w//2, y + h//2)
                
                if direction == "center":
                    arrow_end = (face_center[0], face_center[1] - 120)
                    color = (0, 255, 0)
                    label = "PUBLIKUM: DIREKT ✓✓✓"
                elif direction == "left_slight":
                    arrow_end = (face_center[0] - 80, face_center[1] - 80)
                    color = (0, 220, 0)
                    label = "PUBLIKUM: LINKS ✓✓"
                elif direction == "right_slight":
                    arrow_end = (face_center[0] + 80, face_center[1] - 80)
                    color = (0, 220, 0)
                    label = "PUBLIKUM: RECHTS ✓✓"
                elif direction == "left_far":
                    arrow_end = (face_center[0] - 120, face_center[1])
                    color = (0, 0, 255)
                    label = "WEGGESCHAUT: LINKS ✗"
                elif direction == "right_far":
                    arrow_end = (face_center[0] + 120, face_center[1])
                    color = (0, 0, 255)
                    label = "WEGGESCHAUT: RECHTS ✗"
                else:
                    arrow_end = (face_center[0], face_center[1] + 100)
                    color = (0, 100, 255)
                    label = "BLICK: UNTEN/OBEN"
                
                cv2.arrowedLine(debug_frame, face_center, arrow_end, color, 5, tipLength=0.3)
                
                # Label oben
                label_bg_height = 60
                overlay = debug_frame.copy()
                cv2.rectangle(overlay, (0, 0), (width, label_bg_height), (0, 0, 0), -1)
                cv2.addWeighted(overlay, 0.7, debug_frame, 0.3, 0, debug_frame)
                
                cv2.putText(debug_frame, label, (15, 40), 
                            cv2.FONT_HERSHEY_SIMPLEX, 1.2, color, 3)
                
                conf_text = f"Sicherheit: {int(confidence * 100)}%"
                cv2.putText(debug_frame, conf_text, (width - 280, 40), 
                            cv2.FONT_HERSHEY_SIMPLEX, 0.8, (255, 255, 255), 2)
                
            else:
                frames_without_face += 1
                gaze_samples.append("away")
                gaze_confidence_samples.append(0.3)
                
                # EVENT: Kein Gesicht für längere Zeit
                if frames_without_face == int(fps * 4):
                    self._add_event(
                        timestamp_sec=timestamp_sec - 4,
                        event_type="no_face",
                        severity="critical",
                        description=f"Kein Gesicht erkannt (ab {self._format_time(timestamp_sec - 4)})"
                    )
                
                # Warnung
                label = "KEIN GESICHT ERKANNT ✗✗✗"
                color = (0, 0, 255)
                
                overlay = debug_frame.copy()
                cv2.rectangle(overlay, (0, 0), (width, 60), (0, 0, 0), -1)
                cv2.addWeighted(overlay, 0.7, debug_frame, 0.3, 0, debug_frame)
                
                cv2.putText(debug_frame, label, (15, 40), 
                            cv2.FONT_HERSHEY_SIMPLEX, 1.2, color, 3)
            
            # PHASEN-CHECK (alle 10 Sekunden)
            if timestamp_sec - last_phase_check >= phase_interval and len(phase_gaze_samples) > 0:
                audience_count = sum(1 for g in phase_gaze_samples if g == "audience")
                phase_percentage = int((audience_count / len(phase_gaze_samples)) * 100)
                
                # Bewerte Phase
                if phase_percentage >= 80:
                    self._add_event(
                        timestamp_sec=last_phase_check,
                        event_type="good_phase",
                        severity="positive",
                        description=f"Starke Phase: {phase_percentage}% Publikumskontakt",
                        score=phase_percentage
                    )
                elif phase_percentage < 30:
                    self._add_event(
                        timestamp_sec=last_phase_check,
                        event_type="weak_phase",
                        severity="negative",
                        description=f"Schwache Phase: Nur {phase_percentage}% Publikumskontakt",
                        score=phase_percentage
                    )
                
                last_phase_check = timestamp_sec
                phase_gaze_samples = []
            
            # STATUS-CHECK (alle 30 Sekunden)
            if timestamp_sec - last_status_check >= status_check_interval and len(gaze_samples) > 0:
                current_audience_count = sum(1 for g in gaze_samples if g == "audience")
                current_percentage = int((current_audience_count / len(gaze_samples)) * 100)
                
                self._add_event(
                    timestamp_sec=timestamp_sec,
                    event_type="status_check",
                    severity="neutral",
                    description=f"Status nach {int(timestamp_sec)}s: {current_percentage}% Publikumskontakt",
                    score=current_percentage
                )
                
                last_status_check = timestamp_sec
            
            # Metriken-Overlay unten
            overlay = debug_frame.copy()
            cv2.rectangle(overlay, (0, height - 110), (width, height), (0, 0, 0), -1)
            cv2.addWeighted(overlay, 0.7, debug_frame, 0.3, 0, debug_frame)
            
            cv2.putText(debug_frame, f"Frame: {frame_idx}/{total_frames}", (15, height - 75), 
                        cv2.FONT_HERSHEY_SIMPLEX, 0.7, (255, 255, 255), 2)
            cv2.putText(debug_frame, f"Zeit: {timestamp_sec:.2f}s / {duration_sec:.1f}s", (15, height - 45), 
                        cv2.FONT_HERSHEY_SIMPLEX, 0.7, (255, 255, 255), 2)
            
            # Live-Statistik
            if len(gaze_samples) > 0:
                audience_count = sum(1 for g in gaze_samples if g == "audience")
                current_percentage = int((audience_count / len(gaze_samples)) * 100)
                cv2.putText(debug_frame, f"Publikum: {current_percentage}% ({audience_count}/{len(gaze_samples)})", 
                            (15, height - 15), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 255), 2)
            
            # Schreibe Debug-Frame
            if out is not None:
                out.write(debug_frame)
            
            frame_idx += 1
        
        cap.release()
        if out is not None:
            out.release()
        
        # Metriken aggregieren
        metrics = self._create_metrics(
            duration_sec, 
            brightness_samples, 
            motion_samples,
            gaze_samples,
            gaze_confidence_samples
        )
        
        highlights = self._generate_highlights(metrics, debug_video_path)
        time_segments = self._detect_time_segments(gaze_samples, fps)
        
        # Events sortieren: Positive zuerst, dann nach Zeit
        sorted_events = sorted(
            self.timestamp_events, 
            key=lambda e: (
                0 if e.severity == "positive" else 
                1 if e.severity == "neutral" else 
                2 if e.severity == "negative" else 3,
                e.timestamp_sec
            )
        )
        
        # Limitiere auf Top 20 Events
        sorted_events = sorted_events[:20]
        
        return KeyPoints(
            metrics=metrics,
            highlights=highlights,
            time_segments=time_segments,
            timestamp_events=sorted_events
        )
    
    def _add_event(self, timestamp_sec: float, event_type: str, severity: str, description: str, score: Optional[int] = None):
            """Fügt ein Timestamp-Event hinzu (vermeidet Duplikate)"""
            
            # Prüfe ob ähnliches Event in den letzten 3 Sekunden existiert
            for event in self.timestamp_events:
                if (event.event_type == event_type and 
                    abs(event.timestamp_sec - timestamp_sec) < 3):
                    return  # Duplikat
            
            self.timestamp_events.append(TimestampEvent(
                timestamp_sec=round(timestamp_sec, 1),
                event_type=event_type,
                severity=severity,
                description=description,
                score=score
            ))

    def _format_time(self, seconds: float) -> str:
        """Formatiert Sekunden zu MM:SS"""
        mins = int(seconds // 60)
        secs = int(seconds % 60)
        return f"{mins}:{secs:02d}"
    
    def _detect_gaze(self, gray: np.ndarray, frame: np.ndarray, last_face: Optional[Tuple] = None) -> Tuple[Dict, float]:
        """Erkennt Blickrichtung mit hoher Genauigkeit"""
        
        # Versuche Gesicht zu finden (mit Tracking-Unterstützung)
        faces = self.face_cascade.detectMultiScale(gray, 1.3, 5)
        
        if len(faces) == 0:
            # Backup: Alternativer Cascade
            faces = self.face_cascade_alt.detectMultiScale(gray, 1.3, 5)
        
        if len(faces) == 0 and last_face is not None:
            # Versuche im letzten bekannten Bereich
            lx, ly, lw, lh = last_face
            search_margin = 50
            search_x = max(0, lx - search_margin)
            search_y = max(0, ly - search_margin)
            search_w = min(gray.shape[1] - search_x, lw + 2*search_margin)
            search_h = min(gray.shape[0] - search_y, lh + 2*search_margin)
            
            roi = gray[search_y:search_y+search_h, search_x:search_x+search_w]
            faces_roi = self.face_cascade.detectMultiScale(roi, 1.2, 4)
            
            if len(faces_roi) > 0:
                fx, fy, fw, fh = faces_roi[0]
                faces = [(search_x + fx, search_y + fy, fw, fh)]
        
        if len(faces) == 0:
            return {
                "face_detected": False,
                "face_rect": None,
                "eyes": [],
                "direction": "unknown",
                "is_audience": False
            }, 0.0
        
        # Nimm größtes Gesicht
        faces_sorted = sorted(faces, key=lambda x: x[2] * x[3], reverse=True)
        x, y, w, h = faces_sorted[0]
        
        # ROI für Augen (obere Hälfte des Gesichts)
        roi_gray = gray[y:y+int(h*0.7), x:x+w]
        
        # Versuche Augen zu finden (mehrere Methoden)
        eyes = self.eye_cascade.detectMultiScale(roi_gray, 1.1, 5, minSize=(20, 20))
        
        if len(eyes) < 2:
            # Backup: Augen mit Brillen-Erkennung
            eyes = self.eye_cascade_glasses.detectMultiScale(roi_gray, 1.1, 5, minSize=(20, 20))
        
        confidence = 0.6  # Basis-Confidence
        
        if len(eyes) >= 2:
            confidence = 0.95
            
            # Sortiere Augen nach X-Position
            eyes_sorted = sorted(eyes, key=lambda e: e[0])
            left_eye = eyes_sorted[0]
            right_eye = eyes_sorted[-1]
            
            # Berechne Augen-Mittelpunkt
            left_eye_center_x = left_eye[0] + left_eye[2]//2
            right_eye_center_x = right_eye[0] + right_eye[2]//2
            eyes_center_x = (left_eye_center_x + right_eye_center_x) // 2
            
            # Gesichts-Mitte
            face_center_x = w // 2
            
            # Offset berechnen
            offset = eyes_center_x - face_center_x
            
            # VERBESSERTE SCHWELLWERTE
            if abs(offset) < w * 0.15:  # Innerhalb von 15% der Gesichtsbreite
                if abs(offset) < w * 0.05:
                    direction = "center"
                elif offset < 0:
                    direction = "left_slight"
                else:
                    direction = "right_slight"
                is_audience = True
            elif abs(offset) < w * 0.35:  # 15-35% = noch OK
                if offset < 0:
                    direction = "left_slight"
                else:
                    direction = "right_slight"
                is_audience = True
                confidence = 0.8
            else:  # > 35% = wirklich weggeschaut
                if offset < 0:
                    direction = "left_far"
                else:
                    direction = "right_far"
                is_audience = False
                confidence = 0.9
            
            # Smoothing mit Buffer
            self.gaze_buffer.append(is_audience)
            smoothed_audience = sum(self.gaze_buffer) / len(self.gaze_buffer) > 0.5
            
            return {
                "face_detected": True,
                "face_rect": (x, y, w, h),
                "eyes": eyes_sorted[:2],
                "direction": direction,
                "is_audience": smoothed_audience
            }, confidence
            
        elif len(eyes) == 1:
            # Ein Auge erkannt - Person schaut wahrscheinlich seitlich
            confidence = 0.7
            
            eye = eyes[0]
            eye_center_x = eye[0] + eye[2]//2
            face_center_x = w // 2
            offset = eye_center_x - face_center_x
            
            # Moderate Bewertung
            if abs(offset) < w * 0.25:
                direction = "left_slight" if offset < 0 else "right_slight"
                is_audience = True
            else:
                direction = "left_far" if offset < 0 else "right_far"
                is_audience = False
            
            return {
                "face_detected": True,
                "face_rect": (x, y, w, h),
                "eyes": [eye],
                "direction": direction,
                "is_audience": is_audience
            }, confidence
        
        else:
            # Keine Augen - verwende Gesichts-Position
            confidence = 0.5
            
            # Gesicht im Frame-Bereich?
            frame_center_x = gray.shape[1] // 2
            face_center_x = x + w // 2
            offset = face_center_x - frame_center_x
            
            # Grobe Schätzung basierend auf Gesichtsposition
            if abs(offset) < gray.shape[1] * 0.2:
                direction = "center"
                is_audience = True
            elif abs(offset) < gray.shape[1] * 0.35:
                direction = "left_slight" if offset < 0 else "right_slight"
                is_audience = True
            else:
                direction = "left_far" if offset < 0 else "right_far"
                is_audience = False
            
            return {
                "face_detected": True,
                "face_rect": (x, y, w, h),
                "eyes": [],
                "direction": direction,
                "is_audience": is_audience
            }, confidence
    
    def _create_metrics(
        self, 
        duration_sec: float, 
        brightness_samples: List[float],
        motion_samples: List[float],
        gaze_samples: List[str],
        confidence_samples: List[float]
    ) -> Dict[str, Any]:
        """Erstellt Metriken basierend auf CV-Daten"""
        
        avg_brightness = np.mean(brightness_samples) if brightness_samples else 128
        avg_motion = np.mean(motion_samples) if motion_samples else 5.0
        
        # Blickrichtung berechnen
        audience_gaze_count = sum(1 for g in gaze_samples if g == "audience")
        audience_gaze_percent = int((audience_gaze_count / max(1, len(gaze_samples))) * 100)
        
        # Durchschnittliche Confidence
        avg_confidence = np.mean(confidence_samples) if confidence_samples else 0.5
        
        # Heuristik für Haltung
        posture_score = min(1.0, max(0.3, 1.0 - (avg_motion / 30)))
        
        # Gestik-Aktivität
        gesture_activity = avg_motion / 15
        
        return {
            "duration_sec": round(duration_sec, 1),
            "audience_gaze_percent": audience_gaze_percent,
            "avg_posture_score": round(float(posture_score), 2),
            "avg_movement": round(float(avg_motion), 4),
            "avg_gesture_activity": round(float(gesture_activity), 3),
            "total_frames_analyzed": len(gaze_samples),
            "gaze_samples_total": len(gaze_samples),
            "gaze_samples_audience": audience_gaze_count,
            "avg_confidence": round(float(avg_confidence), 2)
        }
    
    def _generate_highlights(self, metrics: dict, debug_video_path: Optional[str]) -> List[str]:
        """Generiert Key Facts"""
        highlights = []
        
        highlights.append(f"Video-Dauer: {metrics['duration_sec']} Sekunden")
        highlights.append(f"Publikumsblick: {metrics['audience_gaze_percent']}% ({metrics['gaze_samples_audience']}/{metrics['gaze_samples_total']} Frames)")
        highlights.append(f"Durchschnittliche Erkennungs-Sicherheit: {int(metrics['avg_confidence'] * 100)}%")
        highlights.append(f"Analysierte Frames: {metrics['total_frames_analyzed']} (alle Frames!)")
        
        if debug_video_path:
            highlights.append(f"Debug-Video: {Path(debug_video_path).name}")
        
        if metrics['avg_movement'] < 5:
            highlights.append("Sehr ruhige Körperhaltung")
        elif metrics['avg_movement'] > 15:
            highlights.append("Hohe Bewegungsaktivität")
        else:
            highlights.append("Moderate Bewegungsaktivität")
        
        if metrics['audience_gaze_percent'] > 75:
            highlights.append("Exzellenter Publikumskontakt!")
        elif metrics['audience_gaze_percent'] > 60:
            highlights.append("Sehr guter Publikumskontakt")
        elif metrics['audience_gaze_percent'] > 45:
            highlights.append("Guter Publikumskontakt")
        elif metrics['audience_gaze_percent'] < 30:
            highlights.append("Publikumskontakt verbesserungswürdig")
        
        return highlights
    
    def _detect_time_segments(
        self, 
        gaze_samples: List[str],
        fps: float
    ) -> List[TimeSegment]:
        """Findet auffällige Zeitbereiche"""
        segments = []
        
        if not gaze_samples:
            return segments
        
        # Finde längere Phasen ohne Publikumsblick
        away_streak = 0
        away_start = None
        
        for i, gaze in enumerate(gaze_samples):
            timestamp = i / fps
            
            if gaze == "away":
                if away_start is None:
                    away_start = timestamp
                away_streak += 1
            else:
                if away_streak > fps * 3:  # Mehr als 3 Sekunden
                    segments.append(TimeSegment(
                        start_sec=round(away_start, 1),
                        end_sec=round(timestamp, 1),
                        label=f"Ohne Publikumsblick ({away_streak} Frames)"
                    ))
                away_streak = 0
                away_start = None
        
        return segments[:10]

video_analyzer = VideoAnalyzer()