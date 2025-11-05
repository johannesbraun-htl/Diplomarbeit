import os
import cv2
import tempfile
import mediapipe as mp
import mysql.connector
from mysql.connector import Error
from collections import deque

os.environ["OMP_NUM_THREADS"] = "1"
os.environ["TF_NUM_INTEROP_THREADS"] = "1"
os.environ["TF_NUM_INTRAOP_THREADS"] = "1"

cv2.setNumThreads(1)

# ========= OPTIONEN =========
USE_SAVE_DIALOG = True  # True -> Tk Save-Dialog für Ausgabedatei; False -> auto-Pfad
ROUND_DB_PERCENT = 2    # Nachkommastellen für DB-Speicherung

# ======== MYSQL KONFIGURATION ========
MYSQL_HOST = "91.151.18.23"
MYSQL_PORT = 3307
MYSQL_USER = "h109556_admin"
MYSQL_PASS = "!PresentAI"
MYSQL_DB   = "h109556_presentai"

# ======== DETERMINISTISCHE PARAMETER (framebasiert) ========
MA_WIN_FRAMES      = 15
SENSITIVITY        = 0.08
HYST_EXTRA         = 0.04
MIN_STATE_FRAMES   = 15
VOTE_WINDOW_FRAMES = 21

# Render-Einstellungen
DRAW_MESH = True
FONT = cv2.FONT_HERSHEY_SIMPLEX

# MediaPipe
mp_face_mesh = mp.solutions.face_mesh
mp_draw = mp.solutions.drawing_utils
mp_styles = mp.solutions.drawing_styles

LEFT_EYE_CORNERS  = (33, 133)
RIGHT_EYE_CORNERS = (362, 263)
LEFT_IRIS  = [468, 469, 470, 471]
RIGHT_IRIS = [473, 474, 475, 476]

def eye_ratio(lms, idx_corners, idx_iris, w):
    p_out = lms[idx_corners[0]]
    p_in  = lms[idx_corners[1]]
    x_out, x_in = p_out.x * w, p_in.x * w
    iris_x = sum(lms[i].x for i in idx_iris) / len(idx_iris) * w
    denom = (x_in - x_out) or 1e-6
    r = (iris_x - x_out) / denom
    return 0.0 if r < 0 else 1.0 if r > 1 else r

# ======== DB-Helfer ========
def fetch_video_blob(presentations_id: int) -> bytes:
    # Liest MP4-BLOB aus presentations.video.
    conn = cur = None
    try:
        conn = mysql.connector.connect(
            host=MYSQL_HOST, port=MYSQL_PORT, user=MYSQL_USER,
            password=MYSQL_PASS, database=MYSQL_DB
        )
        cur = conn.cursor()
        cur.execute(
            "SELECT OCTET_LENGTH(`video`), `video` FROM `presentations` WHERE `presentations_id` = %s",
            (presentations_id,)
        )
        row = cur.fetchone()
        if not row or row[1] is None:
            raise RuntimeError(f"Kein Video-BLOB für presentations_id={presentations_id}")
        db_len, blob = row[0], row[1]
        if len(blob) != db_len:
            raise RuntimeError(f"BLOB-Truncation: Python {len(blob)} vs DB {db_len}")
        # einfacher MP4-Header-Check
        head = bytes(blob[:64])
        if b"ftyp" not in head:
            # Nicht hart abbrechen – kann bei exotischen Containern fehlen; Hinweis geben.
            print("Hinweis: 'ftyp' nicht in den ersten Bytes gefunden. Stelle sicher, dass es ein MP4/MOV ist.")
        return blob
    except Error as e:
        raise RuntimeError(f"MySQL-Fehler (Select): {e}")
    finally:
        try:
            if cur: cur.close()
            if conn: conn.close()
        except Exception:
            pass

def insert_viewer_percent(viewer_percent: float, fk_presentations_id: int):
    conn = cur = None
    try:
        conn = mysql.connector.connect(
            host=MYSQL_HOST, port=MYSQL_PORT, user=MYSQL_USER,
            password=MYSQL_PASS, database=MYSQL_DB
        )
        cur = conn.cursor()
        cur.execute(
            f"INSERT INTO line_of_sight (viewer_percent, fk_presentations_id) VALUES (%s, %s)",
            (round(float(viewer_percent), ROUND_DB_PERCENT), int(fk_presentations_id))
        )
        conn.commit()
    except Error as e:
        print(f"❌ MySQL-Fehler (Insert): {e}")
    finally:
        try:
            if cur: cur.close()
            if conn: conn.close()
        except Exception:
            pass

# ======== Datei-Materialisierung ========
def write_blob_to_temp_mp4(blob: bytes) -> str:
    tmp = tempfile.NamedTemporaryFile(prefix="presentai_", suffix=".mp4", delete=False)
    with open(tmp.name, "wb") as f:
        f.write(blob)
    return tmp.name

def choose_output_path(default_from_tmp: str, presentations_id: int) -> str:
    # Wählt Ausgabepfad (optional Dialog).
    base = f"presentations_{presentations_id}_annotiert.mp4"
    default_out = os.path.join(os.getcwd(), base)
    if not USE_SAVE_DIALOG:
        return default_out
    # Save-Dialog nur, wenn verfügbar (Headless vermeiden)
    try:
        from tkinter import Tk, filedialog
        Tk().withdraw()
        path = filedialog.asksaveasfilename(
            title="Ausgabevideo speichern unter…",
            defaultextension=".mp4",
            initialfile=base,
            filetypes=[("MP4-Video", "*.mp4")]
        )
        if not path:
            print("⚠️ Kein Speicherort gewählt. Nutze Standardpfad:", default_out)
            return default_out
        return path
    except Exception:
        print("⚠️ Konnte keinen Save-Dialog öffnen. Nutze Standardpfad:", default_out)
        return default_out

# ======== Analyse + Rendering ========
def analyze_and_render(video_in: str, video_out: str) -> float:
    cap = cv2.VideoCapture(video_in, cv2.CAP_FFMPEG)
    if not cap.isOpened():
        raise RuntimeError(f"Konnte Video nicht öffnen: {video_in}")

    width  = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH) or 0)
    height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT) or 0)
    fps    = cap.get(cv2.CAP_PROP_FPS) or 30.0

    # Fallback für Encoder (mp4v ist am kompatibelsten)
    fourcc = cv2.VideoWriter_fourcc(*"mp4v")
    writer = cv2.VideoWriter(video_out, fourcc, fps, (width, height))
    if not writer.isOpened():
        raise RuntimeError(f"Konnte Ausgabedatei nicht schreiben: {video_out}")

    # Framebasierte Konstanten
    min_state_frames = max(1, int(round(MIN_STATE_FRAMES * (fps / 30.0))))
    ma_win = MA_WIN_FRAMES | 1
    vote_win = VOTE_WINDOW_FRAMES | 1
    enter_left  = 0.5 - SENSITIVITY
    enter_right = 0.5 + SENSITIVITY
    exit_left   = 0.5 - (SENSITIVITY + HYST_EXTRA)
    exit_right  = 0.5 + (SENSITIVITY + HYST_EXTRA)

    # Puffer
    r_ma = deque(maxlen=ma_win)
    vote_buf = deque(maxlen=vote_win)

    # Zustände
    dir_state = "Mitte"
    shown_state = "Publikum"
    shown_state_age = 0
    last_r_valid = None
    last_lms = None

    total_considered = 0
    audience_frames  = 0

    # MediaPipe Styles
    tess_style = mp_styles.get_default_face_mesh_tesselation_style()
    cont_style = mp_styles.get_default_face_mesh_contours_style()
    iris_style = mp_styles.get_default_face_mesh_iris_connections_style()

    with mp_face_mesh.FaceMesh(
        max_num_faces=1, refine_landmarks=True,
        min_detection_confidence=0.5, min_tracking_confidence=0.5
    ) as face_mesh:

        while True:
            ok, frame = cap.read()
            if not ok:
                break

            rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
            res = face_mesh.process(rgb)

            # r bestimmen (LOCF bei Drop)
            if res.multi_face_landmarks:
                face = res.multi_face_landmarks[0]
                lms = face.landmark
                h, w = frame.shape[:2]
                rL = eye_ratio(lms, LEFT_EYE_CORNERS, LEFT_IRIS, w)
                rR = eye_ratio(lms, RIGHT_EYE_CORNERS, RIGHT_IRIS, w)
                r  = (rL + rR) / 2.0
                last_r_valid = r
                last_lms = face
            else:
                if last_r_valid is None:
                    last_r_valid = 0.5
                r = last_r_valid

            # Gleitmittel über r
            r_ma.append(r)
            r_avg = sum(r_ma) / len(r_ma)

            # Richtung
            if dir_state == "Mitte":
                if r_avg <= enter_left:
                    dir_state = "Links";  state_target = "Nicht Publikum"
                elif r_avg >= enter_right:
                    dir_state = "Rechts"; state_target = "Nicht Publikum"
                else:
                    state_target = "Publikum"
            elif dir_state == "Links":
                state_target = "Nicht Publikum"
                if r_avg >= exit_left:
                    dir_state = "Mitte"; state_target = "Publikum"
            else:  # Rechts
                state_target = "Nicht Publikum"
                if r_avg <= exit_right:
                    dir_state = "Mitte"; state_target = "Publikum"

            # Debounce
            if state_target == shown_state:
                shown_state_age = min(shown_state_age + 1, 10**9)
            else:
                if shown_state_age >= min_state_frames:
                    shown_state = state_target
                    shown_state_age = 1
                else:
                    shown_state_age += 1

            # Mehrheitsfilter
            vote_buf.append(1 if shown_state == "Publikum" else 0)
            majority_pub = 1 if (sum(vote_buf) * 2 > len(vote_buf)) else 0

            total_considered += 1
            audience_frames  += majority_pub

            # ====== Rendering ======
            if DRAW_MESH and (res.multi_face_landmarks or last_lms is not None):
                face_for_draw = res.multi_face_landmarks[0] if res.multi_face_landmarks else last_lms
                try:
                    mp_draw.draw_landmarks(
                        image=frame,
                        landmark_list=face_for_draw,
                        connections=mp_face_mesh.FACEMESH_TESSELATION,
                        landmark_drawing_spec=None,
                        connection_drawing_spec=tess_style
                    )
                    mp_draw.draw_landmarks(
                        image=frame,
                        landmark_list=face_for_draw,
                        connections=mp_face_mesh.FACEMESH_CONTOURS,
                        landmark_drawing_spec=None,
                        connection_drawing_spec=cont_style
                    )
                    mp_draw.draw_landmarks(
                        image=frame,
                        landmark_list=face_for_draw,
                        connections=mp_face_mesh.FACEMESH_IRISES,
                        landmark_drawing_spec=None,
                        connection_drawing_spec=iris_style
                    )
                except Exception:
                    pass

            color = (0, 200, 0) if majority_pub == 1 else (0, 0, 220)
            cv2.putText(frame, f"{'Publikum' if majority_pub==1 else 'Nicht Publikum'}",
                        (12, 36), FONT, 1.0, color, 2, cv2.LINE_AA)
            pct_so_far = (audience_frames / total_considered * 100.0) if total_considered else 0.0
            cv2.putText(frame, f"{pct_so_far:5.2f}%",
                        (12, 66), FONT, 0.8, (255, 255, 255), 2, cv2.LINE_AA)
            cv2.putText(frame, f"r_avg={r_avg:.3f} | dir={dir_state} | win={len(vote_buf)}",
                        (12, max(0, frame.shape[0]-12)), FONT, 0.55, (220, 220, 220), 1, cv2.LINE_AA)

            writer.write(frame)

    writer.release()
    cap.release()

    final_pct = (audience_frames / total_considered * 100.0) if total_considered else 0.0
    return final_pct

# ======== MAIN ========
def main():
    # presentations_id aus Datei lesen
    with open("data.txt", "r", encoding="utf-8") as f:
        presentations_id = int(f.readline().strip())
    fk_presentations_id = presentations_id

    # 1) Video-BLOB aus DB laden
    blob = fetch_video_blob(presentations_id)

    # 2) In temporäre .mp4 schreiben
    tmp_in = write_blob_to_temp_mp4(blob)

    # 3) Zielpfad für annotiertes Video bestimmen
    out_path = choose_output_path(tmp_in, presentations_id)

    try:
        # 4) Analysieren & Rendern
        percent = analyze_and_render(tmp_in, out_path)

        # 5) Ergebnis in DB schreiben
        insert_viewer_percent(percent, fk_presentations_id)
    finally:
        # 6) Cleanup Temp
        try:
            if os.path.exists(tmp_in):
                os.remove(tmp_in)
        except Exception as e:
            print(f"⚠️ Konnte Tempdatei nicht löschen: {e}")

if __name__ == "__main__":
    main()
