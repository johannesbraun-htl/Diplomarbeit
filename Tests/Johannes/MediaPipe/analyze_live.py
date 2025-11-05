import cv2
import time
import mediapipe as mp

mp_face_mesh = mp.solutions.face_mesh

# ========= Parameter =========
# 1) Messwert-Glättung & Schwellen (auf r in [0..1] um 0.5)
EMA_ALPHA     = 0.25        # 0..1 | höher = reaktiver, niedriger = stabiler
SENSITIVITY   = 0.05        # 0.03..0.08 | Deadzone-Hälfte um 0.5
ENTER_LEFT    = 0.5 - SENSITIVITY
ENTER_RIGHT   = 0.5 + SENSITIVITY
EXIT_LEFT     = 0.5 - (SENSITIVITY + 0.03)   # Hysterese zurück Richtung Mitte
EXIT_RIGHT    = 0.5 + (SENSITIVITY + 0.03)

# 2) Zustands-Konfidenz (tiefe Glättung des Zustands)
CONF_ALPHA    = 0.22        # 0..1 | wie schnell die Konfidenz dem Ziel folgt
CONF_ENTER    = 0.40        # ab welcher |Konfidenz| darf umgeschaltet werden
CONF_EXIT     = 0.20        # Hysterese zurück (kleiner als ENTER)

# 3) Mindest-Verweildauer im Zustand (Debounce)
MIN_STATE_SEC = 0.40        # mind. 0.4 s im neuen „Wunschzustand“, bevor Wechsel erlaubt ist

DRAW_ALL      = True

# MediaPipe-Landmarks
LEFT_EYE_CORNERS  = (33, 133)
RIGHT_EYE_CORNERS = (362, 263)
LEFT_IRIS  = [468, 469, 470, 471]
RIGHT_IRIS = [473, 474, 475, 476]

def eye_ratio(lms, idx_corners, idx_iris, w):
    """Horizontale Irisposition (0=außen, 1=innen)."""
    p_out = lms[idx_corners[0]]
    p_in  = lms[idx_corners[1]]
    x_out, x_in = p_out.x * w, p_in.x * w
    iris_x = sum(lms[i].x for i in idx_iris) / len(idx_iris) * w
    denom = (x_in - x_out) or 1e-6
    r = (iris_x - x_out) / denom
    return max(0.0, min(1.0, r))

def main():
    cap = cv2.VideoCapture(0)
    if not cap.isOpened():
        raise RuntimeError("Keine Kamera gefunden.")

    with mp_face_mesh.FaceMesh(
        max_num_faces=1,
        refine_landmarks=True,
        min_detection_confidence=0.5,
        min_tracking_confidence=0.5
    ) as face_mesh:

        ema = None                     # geglätteter r-Wert
        dir_state = "Mitte"            # "Links" | "Mitte" | "Rechts" (aus Schmitt-Trigger)
        # Konfidenz für Publikumszustand: +1 = Publikum, -1 = Nicht Publikum
        conf = 0.0
        visible_prev = False

        # finaler Anzeigestatus + Debounce
        shown_state = "Publikum"       # was wir anzeigen
        shown_state_since = time.time()

        # Statistik
        t0 = time.time()
        total_frames = 0
        audience_frames = 0

        while True:
            ok, frame = cap.read()
            if not ok:
                break
            frame = cv2.flip(frame, 1)
            h, w = frame.shape[:2]
            rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
            res = face_mesh.process(rgb)

            looking_at_audience = False
            now = time.time()

            if res.multi_face_landmarks:
                face = res.multi_face_landmarks[0]
                lms = face.landmark

                rL = eye_ratio(lms, LEFT_EYE_CORNERS, LEFT_IRIS, w)
                rR = eye_ratio(lms, RIGHT_EYE_CORNERS, RIGHT_IRIS, w)
                r  = (rL + rR) / 2.0

                # 1) Messwert-EMA
                ema = r if ema is None else (EMA_ALPHA * r + (1 - EMA_ALPHA) * ema)

                # 2) Schmitt-Trigger auf EMA -> grobe Richtung
                if dir_state == "Mitte":
                    if ema <= ENTER_LEFT:   dir_state = "Links"
                    elif ema >= ENTER_RIGHT: dir_state = "Rechts"
                elif dir_state == "Links":
                    if ema >= EXIT_LEFT:     dir_state = "Mitte"
                elif dir_state == "Rechts":
                    if ema <= EXIT_RIGHT:    dir_state = "Mitte"

                # 3) Ziel für Konfidenz: Publikum=+1 (nur bei Mitte), sonst -1
                target = +1.0 if dir_state == "Mitte" else -1.0
                # Leaky Integrator (tiefer Tiefpass auf den Zustand)
                conf = conf + CONF_ALPHA * (target - conf)

                # 4) Hysterese + Mindest-Verweildauer auf dem finalen Anzeigestatus
                #    – Umschalten nur, wenn Konfidenz die Hürde (ENTER/EXIT) überschreitet
                #      UND der „Wunschzustand“ (Publikum vs Nicht) lang genug anliegt.
                want_state = "Publikum" if conf >= 0 else "Nicht Publikum"
                conf_abs = abs(conf)

                can_switch = (now - shown_state_since) >= MIN_STATE_SEC
                if shown_state == "Publikum":
                    # nur wegschalten, wenn Konfidenz klar gegen Publikum ist
                    if (want_state == "Nicht Publikum") and can_switch and (conf_abs >= CONF_ENTER):
                        shown_state = "Nicht Publikum"
                        shown_state_since = now
                else:
                    # zurück zu Publikum nur mit kleinerer Schwelle (EXIT)
                    if (want_state == "Publikum") and can_switch and (conf_abs >= CONF_EXIT):
                        shown_state = "Publikum"
                        shown_state_since = now

                looking_at_audience = (shown_state == "Publikum")

                # Darstellung
                color = (0, 255, 0) if looking_at_audience else (0, 0, 255)
                if DRAW_ALL:
                    for lm in lms:
                        x, y = int(lm.x * w), int(lm.y * h)
                        cv2.circle(frame, (x, y), 1, color, -1)

                cv2.putText(frame, shown_state, (10, 30),
                            cv2.FONT_HERSHEY_SIMPLEX, 1.1, color, 2, cv2.LINE_AA)
                if ema is not None:
                    cv2.putText(frame, f"r={ema:.3f}  conf={conf:+.2f}", (10, 60),
                                cv2.FONT_HERSHEY_SIMPLEX, 0.7, (255,255,255), 1, cv2.LINE_AA)
                    cv2.putText(frame, f"dir={dir_state}", (10, 85),
                                cv2.FONT_HERSHEY_SIMPLEX, 0.7, (200,200,200), 1, cv2.LINE_AA)

            # Statistik
            total_frames += 1
            audience_frames += int(looking_at_audience)

            cv2.imshow("Publikums-Erkennung (smooth)  |  ESC = Ende", frame)
            if cv2.waitKey(1) & 0xFF == 27:
                break

    cap.release()
    cv2.destroyAllWindows()

    # Zusammenfassung
    duration_s = time.time() - t0
    pct = (audience_frames / total_frames * 100.0) if total_frames else 0.0
    print("---- Zusammenfassung ----")
    print(f"Dauer [s]:                  {duration_s:.2f}")
    print(f"Frames gesamt:              {total_frames}")
    print(f"Frames 'Publikum':          {audience_frames}")
    print(f"Anteil 'Publikum' [%]:      {pct:.2f}")

if __name__ == "__main__":
    main()
