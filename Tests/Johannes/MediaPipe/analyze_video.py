import os
os.environ["OMP_NUM_THREADS"] = "1"
os.environ["TF_NUM_INTEROP_THREADS"] = "1"
os.environ["TF_NUM_INTRAOP_THREADS"] = "1"

import cv2
cv2.setNumThreads(1)

import tempfile
import mediapipe as mp
import mysql.connector
from mysql.connector import Error
from collections import deque

# ======== FIXE MYSQL KONFIGURATION ========
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

LEFT_EYE_CORNERS  = (33, 133)
RIGHT_EYE_CORNERS = (362, 263)
LEFT_IRIS  = [468, 469, 470, 471]
RIGHT_IRIS = [473, 474, 475, 476]

mp_face_mesh = mp.solutions.face_mesh

# ========= Hilfsfunktionen =========
def eye_ratio(lms, idx_corners, idx_iris, w):
    p_out = lms[idx_corners[0]]
    p_in  = lms[idx_corners[1]]
    x_out, x_in = p_out.x * w, p_in.x * w
    iris_x = sum(lms[i].x for i in idx_iris) / len(idx_iris) * w
    denom = (x_in - x_out) or 1e-6
    r = (iris_x - x_out) / denom
    return 0.0 if r < 0 else 1.0 if r > 1 else r

def insert_viewer_percent(viewer_percent: float, fk_presentations_id: int):
    conn = cur = None
    try:
        conn = mysql.connector.connect(
            host=MYSQL_HOST, port=MYSQL_PORT, user=MYSQL_USER,
            password=MYSQL_PASS, database=MYSQL_DB
        )
        cur = conn.cursor()
        cur.execute(
            "INSERT INTO line_of_sight (viewer_percent, fk_presentations_id) VALUES (%s, %s)",
            (round(viewer_percent, 2), int(fk_presentations_id))
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

def fetch_video_blob(presentations_id: int) -> bytes:
    # Liest den MP4-BLOB aus der DB. Spalte muss BLOB/longblob sein.
    conn = cur = None
    try:
        conn = mysql.connector.connect(
            host=MYSQL_HOST, port=MYSQL_PORT, user=MYSQL_USER,
            password=MYSQL_PASS, database=MYSQL_DB
        )
        cur = conn.cursor()
        # Passe Spaltennamen/Tabellennamen an: hier 'video' als BLOB
        cur.execute(
            "SELECT `video` FROM `presentations` WHERE `presentations_id` = %s",
            (presentations_id,)
        )
        row = cur.fetchone()
        if not row or row[0] is None:
            raise RuntimeError(f"Kein Video-BLOB für presentations_id={presentations_id}")
        return row[0]  # bytes
    except Error as e:
        raise RuntimeError(f"MySQL-Fehler (Select): {e}")
    finally:
        try:
            if cur: cur.close()
            if conn: conn.close()
        except Exception:
            pass

def write_blob_to_temp_mp4(blob: bytes) -> str:
    # Schreibt BLOB in eine temporäre .mp4-Datei und gibt den Pfad zurück.
    # delete=False, damit OpenCV den Pfad öffnen kann
    tmp = tempfile.NamedTemporaryFile(prefix="presentai_", suffix=".mp4", delete=False)
    try:
        tmp.write(blob)
        tmp.flush()
        return tmp.name
    finally:
        tmp.close()

# ========= Kern: deterministische Analyse =========
def analyze_video_percent(video_path: str) -> float:
    cap = cv2.VideoCapture(video_path)
    if not cap.isOpened():
        raise RuntimeError(f"Konnte Video nicht öffnen: {video_path}")

    fps = cap.get(cv2.CAP_PROP_FPS) or 30.0
    min_state_frames   = max(1, int(round(MIN_STATE_FRAMES * (fps / 30.0))))
    ma_win             = max(1, MA_WIN_FRAMES | 1)
    vote_win           = max(1, VOTE_WINDOW_FRAMES | 1)

    enter_left  = 0.5 - SENSITIVITY
    enter_right = 0.5 + SENSITIVITY
    exit_left   = 0.5 - (SENSITIVITY + HYST_EXTRA)
    exit_right  = 0.5 + (SENSITIVITY + HYST_EXTRA)

    r_ma = deque(maxlen=ma_win)
    vote_buf = deque(maxlen=vote_win)

    dir_state = "Mitte"
    shown_state = "Publikum"
    shown_state_age = 0

    total_considered = 0
    audience_frames  = 0
    last_r_valid = None

    with mp_face_mesh.FaceMesh(
        max_num_faces=1,
        refine_landmarks=True,
        min_detection_confidence=0.5,
        min_tracking_confidence=0.5
    ) as face_mesh:

        while True:
            ok, frame = cap.read()
            if not ok:
                break

            h, w = frame.shape[:2]
            rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
            res = face_mesh.process(rgb)

            if res.multi_face_landmarks:
                lms = res.multi_face_landmarks[0].landmark
                rL = eye_ratio(lms, LEFT_EYE_CORNERS, LEFT_IRIS, w)
                rR = eye_ratio(lms, RIGHT_EYE_CORNERS, RIGHT_IRIS, w)
                r = (rL + rR) / 2.0
                last_r_valid = r
            else:
                if last_r_valid is None:
                    last_r_valid = 0.5
                r = last_r_valid

            r_ma.append(r)
            r_avg = sum(r_ma) / len(r_ma)

            if dir_state == "Mitte":
                if r_avg <= enter_left:
                    dir_state = "Links"; state_target = "Nicht Publikum"
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

            if state_target == shown_state:
                shown_state_age = min(shown_state_age + 1, 10**9)
            else:
                if shown_state_age >= min_state_frames:
                    shown_state = state_target
                    shown_state_age = 1
                else:
                    shown_state_age += 1

            vote_buf.append(1 if shown_state == "Publikum" else 0)
            vote_sum = sum(vote_buf)
            majority_pub = 1 if vote_sum * 2 > len(vote_buf) else 0

            total_considered += 1
            audience_frames  += majority_pub

    cap.release()

    return 0.0 if total_considered == 0 else (audience_frames / total_considered) * 100.0

# ============ Hauptprogramm ============
def main():
    f = open("data.txt", "r")
    presentations_id = int(f.readline().strip())
    fk_presentations_id = presentations_id

    # 1) Video-BLOB holen
    blob = fetch_video_blob(presentations_id)

    # 2) Temporäre MP4 schreiben
    tmp_path = write_blob_to_temp_mp4(blob)

    try:
        # 3) Analysieren
        percent = analyze_video_percent(tmp_path)

        # 4) Ergebnis speichern
        insert_viewer_percent(percent, fk_presentations_id)
    finally:
        # 5) Cleanup
        try:
            if os.path.exists(tmp_path):
                os.remove(tmp_path)
        except Exception as e:
            print(f"⚠️ Konnte Tempdatei nicht löschen: {e}")

if __name__ == "__main__":
    main()
