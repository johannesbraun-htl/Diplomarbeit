import os, json, subprocess, hashlib, time, traceback, random, requests

MODEL = os.getenv("OPENAI_MODEL", "gpt-4o-mini")
API_KEY = os.getenv("OPENAI_API_KEY", "")
HERE = os.path.dirname(os.path.abspath(__file__))
CACHE_DIR = os.path.join(HERE, ".cache")
os.makedirs(CACHE_DIR, exist_ok=True)

def write_json_file(path, obj):
    if not path: return
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        json.dump(obj, f, ensure_ascii=False, indent=2)

def append_text_file(path, text):
    if not path: return
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "a", encoding="utf-8") as f:
        f.write(text)

def set_status(status_path, state, percent, message):
    write_json_file(status_path, {"state": state, "percent": int(percent), "message": message, "ts": int(time.time())})

def extract_json_after_marker(text, marker):
    idx = text.find(marker)
    if idx < 0: return None
    s = text[idx + len(marker):]
    start = s.find("{")
    if start < 0: return None
    s = s[start:]
    depth = 0
    end_pos = None
    for i, ch in enumerate(s):
        if ch == "{": depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                end_pos = i
                break
    if end_pos is None: return None
    return json.loads(s[:end_pos + 1])

def file_fingerprint(path):
    st = os.stat(path)
    raw = f"{path}|{st.st_size}|{int(st.st_mtime)}".encode("utf-8")
    return hashlib.sha1(raw).hexdigest()

def run_pose(video_path, sample, max_seconds):
    pose_script = os.path.join(HERE, "pose_confidence_score.py")
    cmd = ["python", pose_script, video_path, "--sample", str(sample), "--max_seconds", str(max_seconds)]
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError(p.stderr.strip() or "pose_confidence_score.py failed")
    score = extract_json_after_marker(p.stdout, "=== SCORE RESULT ===")
    payload = extract_json_after_marker(p.stdout, "=== GPT PAYLOAD (paste into your LLM call) ===")
    if score is None or payload is None:
        raise RuntimeError("Could not extract SCORE/PAYLOAD JSON")
    return score, payload

def safe_feedback_for_empty(pose_score: dict):
    msg = "Keine Person erkannt."
    if isinstance(pose_score, dict) and "error" in pose_score:
        msg = pose_score["error"].get("message", msg)

    return {
        "verdict": f"Ich seh da keine Person – check kurz das Video (leer/zu dunkel/Person nicht im Bild).",
        "score_0_100": int(pose_score.get("total_score_0_100", 0) if isinstance(pose_score, dict) else 0),
        "plus_points": [],
        "minus_points": [],
        "tips": [
            "Stell sicher, dass eine Person im Bild ist (Kopf + Oberkörper reichen).",
            "Mehr Licht oder Kamera näher ran (Kontrast hilft MediaPipe massiv).",
            "Kein komplett schwarzes/standbild Video – sonst gibt’s nichts zu analysieren."
        ]
    }

def call_openai(payload):
    if not API_KEY:
        raise RuntimeError("Missing OPENAI_API_KEY")

    url = "https://api.openai.com/v1/responses"
    mode = payload["analysis"].get("mode", "unknown")
    style_seed = random.randint(1000, 9999)

    system = (
        "Du bist ein lockerer Praesentations-Coach. "
        "Kurz, direkt, motivierend. "
        "Zeigen/Erklaeren ist oft ein Plus. "
        "Gib streng JSON zurueck, ohne Zusatztext."
    )

    rules = [
        "NUR JSON",
        "verdict: 1 Satz locker, 1 Staerke + 1 Hebel",
        "max 5 plus_points, max 5 minus_points, genau 3 tips",
        "tips als Mini-Übungen",
        "keine Emojis",
        "score_0_100 muss analysis.total_score_0_100 sein",
    ]
    if mode == "upper_body":
        rules.append("Keine Tipps zu Fuessen/Beinen/Stand.")

    req = {"style_seed": style_seed, "analysis": payload["analysis"], "rules": rules}

    body = {
        "model": MODEL,
        "temperature": 0.9,
        "input": [
            {"role": "system", "content": system},
            {"role": "user", "content": json.dumps(req, ensure_ascii=False)}
        ],
        "max_output_tokens": 420
    }

    headers = {"Authorization": f"Bearer {API_KEY}", "Content-Type": "application/json"}
    r = requests.post(url, headers=headers, data=json.dumps(body).encode("utf-8"), timeout=90)
    if r.status_code >= 300:
        raise RuntimeError(f"OpenAI HTTP error {r.status_code}: {r.text[:800]}")
    resp = r.json()

    text = resp.get("output_text", "") if isinstance(resp.get("output_text"), str) else ""
    if not text.strip():
        chunks = []
        for item in resp.get("output", []):
            for c in item.get("content", []):
                if c.get("type") == "output_text" and "text" in c:
                    chunks.append(c["text"])
        text = ("\n".join(chunks)).strip()

    if not text:
        raise RuntimeError("No text output from OpenAI")

    try:
        return json.loads(text)
    except:
        s = text[text.find("{"):text.rfind("}")+1]
        return json.loads(s)

def main():
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--video", required=True)
    parser.add_argument("--sample", type=int, default=6)
    parser.add_argument("--max_seconds", type=int, default=35)
    parser.add_argument("--cache_minutes", type=int, default=0)
    parser.add_argument("--out", default="")
    parser.add_argument("--status", default="")
    parser.add_argument("--error", default="")
    args = parser.parse_args()

    try:
        set_status(args.status, "running", 5, "Start...")

        fp = file_fingerprint(args.video)
        cache_file = os.path.join(CACHE_DIR, f"{fp}_s{args.sample}_t{args.max_seconds}.json")

        if args.cache_minutes and args.cache_minutes > 0 and os.path.exists(cache_file):
            age_sec = time.time() - os.path.getmtime(cache_file)
            if age_sec <= args.cache_minutes * 60:
                set_status(args.status, "done", 100, "Cache hit.")
                cached = open(cache_file, "r", encoding="utf-8").read()
                if args.out:
                    os.makedirs(os.path.dirname(args.out), exist_ok=True)
                    open(args.out, "w", encoding="utf-8").write(cached)
                print(cached)
                return

        set_status(args.status, "running", 20, "Pose Analyse...")
        pose_score, payload = run_pose(args.video, sample=args.sample, max_seconds=args.max_seconds)

        # >>> HARD STOP: no person => do NOT call OpenAI
        if isinstance(pose_score, dict) and pose_score.get("error", {}).get("code") == "NO_PERSON_DETECTED":
            feedback = safe_feedback_for_empty(pose_score)
            out = {"pose_score": pose_score, "feedback": feedback}
            out_text = json.dumps(out, ensure_ascii=False)
            if args.out:
                os.makedirs(os.path.dirname(args.out), exist_ok=True)
                open(args.out, "w", encoding="utf-8").write(out_text)
            set_status(args.status, "done", 100, "Fertig (keine Person erkannt).")
            print(out_text)
            return

        set_status(args.status, "running", 80, "Coach Feedback...")
        feedback = call_openai(payload)

        out = {"pose_score": pose_score, "feedback": feedback}
        out_text = json.dumps(out, ensure_ascii=False)

        if args.cache_minutes and args.cache_minutes > 0:
            open(cache_file, "w", encoding="utf-8").write(out_text)

        if args.out:
            os.makedirs(os.path.dirname(args.out), exist_ok=True)
            open(args.out, "w", encoding="utf-8").write(out_text)

        set_status(args.status, "done", 100, "Fertig.")
        print(out_text)

    except Exception as e:
        tb = traceback.format_exc()
        append_text_file(args.error, tb + "\n")
        set_status(args.status, "error", 100, f"Fehler: {str(e)}")
        raise

if __name__ == "__main__":
    main()
