import cv2
import numpy as np
from tqdm import tqdm
from insightface.app import FaceAnalysis

# Initialisierung
app = FaceAnalysis(name='buffalo_l', providers=['CUDAExecutionProvider'])  # nutze CUDA für Geschwindigkeit
app.prepare(ctx_id=0)

# Video laden
cap = cv2.VideoCapture("input_k.mp4")
fps = cap.get(cv2.CAP_PROP_FPS)
width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
frame_count = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))

# Videoausgabe vorbereiten
out = cv2.VideoWriter("output.mp4", cv2.VideoWriter_fourcc(*"mp4v"), fps, (width, height))

for _ in tqdm(range(frame_count), desc="Verarbeite"):
    ret, frame = cap.read()
    if not ret:
        break

    small = cv2.resize(frame, (640, 360))
    faces = app.get(small)

    for face in faces:
        landmarks = face.kps
        left_eye = landmarks[0]
        right_eye = landmarks[1]
        nose = landmarks[2]

        eye_center = ((left_eye + right_eye) / 2).astype(int)

        # Blickrichtung approximieren durch Nase zu Augenmittelpunkt
        gaze_vector = eye_center - nose
        gaze_vector = gaze_vector / (np.linalg.norm(gaze_vector) + 1e-6)

        dx, dy = gaze_vector
        angle = np.arctan2(-dy, dx) * 180 / np.pi
        is_audience = abs(angle) < 15  # Toleranzbereich anpassen

        color = (0, 255, 0) if is_audience else (0, 0, 255)
        label = "Publikum" if is_audience else "Nicht Publikum"

        # Koordinaten in Originalbild hochskalieren
        scale_x = width / 640
        scale_y = height / 360
        ex = int(eye_center[0] * scale_x)
        ey = int(eye_center[1] * scale_y)
        tip = (int(ex + dx * 100), int(ey - dy * 100))

        cv2.arrowedLine(frame, (ex, ey), tip, color, 2)
        cv2.putText(frame, label, (ex - 40, ey - 60), cv2.FONT_HERSHEY_SIMPLEX, 0.6, color, 2)
        cv2.circle(frame, (ex, ey), 4, color, -1)

    out.write(frame)

cap.release()
out.release()
print("✅ Verarbeitung abgeschlossen → output.mp4")
