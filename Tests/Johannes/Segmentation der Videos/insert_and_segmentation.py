'''
Versuch:
Eine Viedeodatei in kleine Segmente (15 Sekunden) unterteilen, lokal speichern
und anschließend in die Datenbank laden.

Grund dazu:
Die Videodatei ist zu groß, um sie in einem Stück in die DB zu laden
und kann teilweise nicht einmal hochgeladen werden, wenn sie über 2.048MiB groß ist.

Quellen:
- W3Schools: File Handling (binär lesen): https://www.w3schools.com/python/python_file_handling.asp
- W3Schools: Python MySQL Basics: https://www.w3schools.com/python/python_mysql_getstarted.asp
- MoviePy (VideoFileClip): https://www.geeksforgeeks.org/python/introduction-to-moviepy
- FFmpeg (Optionen -ss / -t / -map): https://ffmpeg.org/ffmpeg.html

Autor: Johannes Braun
'''

# Importiere notwendige Bibliotheken
import mysql.connector
from moviepy.editor import VideoFileClip
import imageio_ffmpeg
import subprocess
import os
import math

# Datenbank Verbindung
mydb = mysql.connector.connect(
    host="91.151.18.23",
    port=3307,
    user="h109556_admin",
    password="!PresentAI",
    database="h109556_presentai"
)

# Input Datei definieren
input_file = "vortrag.mp4"

# Dauer jedes Segments in Sekunden
segment_duration = 15

# Ausgabeverzeichnis
output_dir = "segments"
os.makedirs(output_dir, exist_ok=True)

# ffmpeg-Binary aus imageio_ffmpeg 
FFMPEG = imageio_ffmpeg.get_ffmpeg_exe()

# Videolänge und Audio prüfen
_master = VideoFileClip(input_file)
duration = int(_master.duration)
source_has_audio = (_master.audio is not None)
_master.close()

# Anzahl der Segmente berechnen
num_segments = max(1, math.ceil(duration / segment_duration))

# Hilfsfunktion für ffmpeg-Aufruf
def _run(cmd: list[str]):
    res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if res.returncode != 0:
        raise RuntimeError(res.stderr.strip())

# Segmente erzeugen und lokal speichern
for i in range(num_segments):
    start = i * segment_duration
    end = min((i + 1) * segment_duration, duration)
    seg_len = end - start
    output_name = os.path.join(output_dir, f"segment_{i+1}.mp4")

    if source_has_audio:
        # Normales Re-Encode mit Audio
        cmd = [
            FFMPEG,
            "-hide_banner", "-loglevel", "error",
            "-ss", str(start),
            "-i", input_file,
            "-t", str(seg_len),
            "-map", "0:v:0",
            "-map", "0:a:0",
            "-c:v", "libx264",
            "-preset", "veryfast",
            "-crf", "23",
            "-c:a", "aac",
            "-b:a", "192k",
            "-ar", "48000",
            "-ac", "2",
            "-movflags", "+faststart",
            "-shortest",
            output_name
        ]
    else:
        # Wenn kein Audio vorhanden, füge stille AAC-Spur hinzu
        cmd = [
            FFMPEG,
            "-hide_banner", "-loglevel", "error",
            "-ss", str(start),
            "-i", input_file,
            "-f", "lavfi", "-t", str(seg_len),
            "-i", "anullsrc=r=48000:cl=stereo",
            "-map", "0:v:0",
            "-map", "1:a:0",
            "-c:v", "libx264",
            "-preset", "veryfast",
            "-crf", "23",
            "-c:a", "aac",
            "-b:a", "192k",
            "-movflags", "+faststart",
            "-shortest",
            output_name
        ]

    _run(cmd)

# Hardcodierter User ID und Hash
user_id = 1
video_hash = "beispielhash1234567890"

# In die Datenbank einfügen
cursor = mydb.cursor()

for i in range(1, num_segments + 1):
    segment_path = os.path.join(output_dir, f"segment_{i}.mp4")
    with open(segment_path, "rb") as file:
        clip_bytes = file.read()
        cursor.execute(
            "INSERT INTO presentations (video_hash, video, fk_user_id) VALUES (%s,%s,%s)",
            (video_hash, clip_bytes, user_id)
        )

# Änderungen speichern
mydb.commit()

# Verbindung schließen
cursor.close()
mydb.close()