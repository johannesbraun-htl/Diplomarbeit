# --- PATCH: block sounddevice import to avoid PortAudio init under Apache/XAMPP ---
import sys, types
if "sounddevice" not in sys.modules:
    sys.modules["sounddevice"] = types.ModuleType("sounddevice")
# -------------------------------------------------------------------------------

import cv2, json, math
import numpy as np
import mediapipe as mp

mp_pose = mp.solutions.pose
LM = mp_pose.PoseLandmark

def clamp(x, lo, hi): return max(lo, min(hi, x))

def safe_median(arr, default=0.0):
    if not arr: return default
    return float(np.median(np.array(arr, dtype=float)))

def safe_mean(arr, default=0.0):
    if not arr: return default
    return float(np.mean(np.array(arr, dtype=float)))

def safe_percentile(arr, p, default=0.0):
    if not arr: return default
    return float(np.percentile(np.array(arr, dtype=float), p))

def dist(a, b): return float(np.linalg.norm(a - b))

def angle_to_vertical(dx, dy):
    refx, refy = 0.0, -1.0
    nv = math.sqrt(dx*dx + dy*dy) + 1e-9
    dot = (dx*refx + dy*refy) / nv
    dot = clamp(dot, -1.0, 1.0)
    return math.degrees(math.acos(dot))

def lm_xy_vis(lms, landmark):
    p = lms[landmark.value]
    return np.array([p.x, p.y], dtype=float), float(p.visibility)

def mid(a, b): return (a + b) / 2.0

def score_smaller_is_better(value, good_max, bad_min):
    if value <= good_max: return 1.0
    if value >= bad_min: return 0.0
    return float(1.0 - (value - good_max) / (bad_min - good_max + 1e-9))

def score_bigger_is_better(value, bad_max, good_min):
    if value <= bad_max: return 0.0
    if value >= good_min: return 1.0
    return float((value - bad_max) / (good_min - bad_max + 1e-9))

def smooth_vec(prev_smooth, cur, alpha=0.80):
    if prev_smooth is None: return cur
    return alpha * prev_smooth + (1.0 - alpha) * cur

def gesture_amplitude(points):
    if len(points) < 10: return 0.0
    arr = np.array(points, dtype=float)
    p10 = np.percentile(arr, 10, axis=0)
    p90 = np.percentile(arr, 90, axis=0)
    return float(np.linalg.norm(p90 - p10))

def empty_result(fps: float, mode: str, reason: str):
    return {
        "verdict": f"Keine Person erkannt: {reason}",
        "total_score_0_100": 0,
        "mode": mode,
        "error": {"code": "NO_PERSON_DETECTED", "message": reason},
        "subscores": {},
        "metrics": {"fps": float(fps), "frames_used": 0, "coverage": {"hips_ratio": 0, "knees_ratio": 0, "ankles_ratio": 0, "mode": mode}},
        "plus_points": [],
        "minus_points": []
    }

def analyze_video(video_path: str, sample_every_n_frames: int = 6, max_seconds: int = 35):
    cap = cv2.VideoCapture(video_path)
    if not cap.isOpened():
        raise RuntimeError(f"Cannot open video: {video_path}")

    fps = cap.get(cv2.CAP_PROP_FPS) or 30.0
    max_frames = int(max_seconds * fps) if (max_seconds and max_seconds > 0) else 0

    torso_angle, shoulder_hip_ratio = [], []
    mid_sh_speed, torso_sway, head_speed = [], [], []
    wrist_speed, hand_balance = [], []

    l_wri_xy, r_wri_xy = [], []
    fidget_flags, pointing_like, explain_point_like = [], [], []
    arms_cross_like = []
    foot_speed = []

    frames_used = 0
    frames_with_hips = 0
    frames_with_ankles = 0
    frames_with_knees = 0

    prev = {"mid_sh_s": None, "mid_hp_s": None, "nose_s": None, "l_wri_s": None, "r_wri_s": None, "l_ank_s": None, "r_ank_s": None}
    frame_idx = 0
    sampled_frames = 0

    with mp_pose.Pose(
        static_image_mode=False,
        model_complexity=0,
        enable_segmentation=False,
        min_detection_confidence=0.5,
        min_tracking_confidence=0.5
    ) as pose:

        while True:
            ok, frame = cap.read()
            if not ok: break
            frame_idx += 1
            if max_frames and frame_idx > max_frames: break
            if (frame_idx % sample_every_n_frames) != 0: continue

            sampled_frames += 1
            rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
            res = pose.process(rgb)
            if not res.pose_landmarks: continue

            lms = res.pose_landmarks.landmark

            l_sh, v_lsh = lm_xy_vis(lms, LM.LEFT_SHOULDER)
            r_sh, v_rsh = lm_xy_vis(lms, LM.RIGHT_SHOULDER)
            l_hip, v_lhp = lm_xy_vis(lms, LM.LEFT_HIP)
            r_hip, v_rhp = lm_xy_vis(lms, LM.RIGHT_HIP)
            nose, v_nose = lm_xy_vis(lms, LM.NOSE)

            l_wri, v_lw = lm_xy_vis(lms, LM.LEFT_WRIST)
            r_wri, v_rw = lm_xy_vis(lms, LM.RIGHT_WRIST)
            l_elb, v_le = lm_xy_vis(lms, LM.LEFT_ELBOW)
            r_elb, v_re = lm_xy_vis(lms, LM.RIGHT_ELBOW)

            l_ank, v_la = lm_xy_vis(lms, LM.LEFT_ANKLE)
            r_ank, v_ra = lm_xy_vis(lms, LM.RIGHT_ANKLE)
            l_knee, v_lk = lm_xy_vis(lms, LM.LEFT_KNEE)
            r_knee, v_rk = lm_xy_vis(lms, LM.RIGHT_KNEE)

            if min(v_lsh, v_rsh, v_nose) < 0.45:
                continue

            frames_used += 1

            hips_visible = (min(v_lhp, v_rhp) >= 0.45)
            ankles_visible = (min(v_la, v_ra) >= 0.45)
            knees_visible = (min(v_lk, v_rk) >= 0.45)
            if hips_visible: frames_with_hips += 1
            if ankles_visible: frames_with_ankles += 1
            if knees_visible: frames_with_knees += 1

            mid_sh = mid(l_sh, r_sh)
            mid_hp = mid(l_hip, r_hip) if hips_visible else None

            scale = (dist(mid_sh, mid_hp) + 1e-9) if hips_visible else (dist(l_sh, r_sh) + 1e-9)

            mid_sh_s = smooth_vec(prev["mid_sh_s"], mid_sh)
            nose_s   = smooth_vec(prev["nose_s"], nose)
            l_wri_s  = smooth_vec(prev["l_wri_s"], l_wri)
            r_wri_s  = smooth_vec(prev["r_wri_s"], r_wri)
            mid_hp_s = smooth_vec(prev["mid_hp_s"], mid_hp) if hips_visible else None

            l_ank_s = smooth_vec(prev["l_ank_s"], l_ank) if ankles_visible else None
            r_ank_s = smooth_vec(prev["r_ank_s"], r_ank) if ankles_visible else None

            if hips_visible:
                tv = mid_sh_s - mid_hp_s
                torso_angle.append(angle_to_vertical(float(tv[0]), float(tv[1])))
                sh_w = dist(l_sh, r_sh) + 1e-9
                hp_w = dist(l_hip, r_hip) + 1e-9
                shoulder_hip_ratio.append(float(sh_w / hp_w))

            if prev["mid_sh_s"] is not None:
                mid_sh_speed.append(dist(mid_sh_s, prev["mid_sh_s"]) / scale * fps)

            if hips_visible and prev["mid_hp_s"] is not None:
                torso_sway.append(dist(mid_hp_s, prev["mid_hp_s"]) / scale * fps)
            elif prev["mid_sh_s"] is not None:
                torso_sway.append(dist(mid_sh_s, prev["mid_sh_s"]) / scale * fps)

            if prev["nose_s"] is not None:
                head_speed.append(dist(nose_s, prev["nose_s"]) / scale * fps)

            lw = 0.0; rw = 0.0
            if prev["l_wri_s"] is not None:
                lw = dist(l_wri_s, prev["l_wri_s"]) / scale * fps
                wrist_speed.append(lw)
            if prev["r_wri_s"] is not None:
                rw = dist(r_wri_s, prev["r_wri_s"]) / scale * fps
                wrist_speed.append(rw)

            if (lw > 0.0 or rw > 0.0):
                hand_balance.append(abs(lw - rw) / (lw + rw + 1e-9))

            l_wri_xy.append(l_wri_s.copy())
            r_wri_xy.append(r_wri_s.copy())

            step_l = (dist(l_wri_s, prev["l_wri_s"]) / scale) if prev["l_wri_s"] is not None else 0.0
            step_r = (dist(r_wri_s, prev["r_wri_s"]) / scale) if prev["r_wri_s"] is not None else 0.0
            fast = (step_l > 0.010) or (step_r > 0.010)
            tiny = (step_l < 0.028) and (step_r < 0.028)
            fidget_flags.append(bool(fast and tiny))

            l_arm_ext = dist(l_wri_s, l_sh) / scale
            r_arm_ext = dist(r_wri_s, r_sh) / scale
            pointing_like.append(bool((l_arm_ext > 1.10) or (r_arm_ext > 1.10)))

            mid_x = float(mid_sh_s[0])
            dy = abs(float(l_wri_s[1]) - float(r_wri_s[1]))
            away = max(abs(float(l_wri_s[0]) - mid_x), abs(float(r_wri_s[0]) - mid_x)) / (scale + 1e-9)
            explain_point_like.append(bool((dy > 0.06) or (away > 0.35)))

            d1 = dist(l_wri_s, r_elb) / scale
            d2 = dist(r_wri_s, l_elb) / scale
            arms_cross_like.append(bool((d1 < 0.45) and (d2 < 0.45)))

            if ankles_visible and prev["l_ank_s"] is not None and l_ank_s is not None:
                foot_speed.append(dist(l_ank_s, prev["l_ank_s"]) / scale * fps)
            if ankles_visible and prev["r_ank_s"] is not None and r_ank_s is not None:
                foot_speed.append(dist(r_ank_s, prev["r_ank_s"]) / scale * fps)

            prev["mid_sh_s"] = mid_sh_s
            prev["nose_s"] = nose_s
            prev["l_wri_s"] = l_wri_s
            prev["r_wri_s"] = r_wri_s
            prev["mid_hp_s"] = mid_hp_s
            prev["l_ank_s"] = l_ank_s
            prev["r_ank_s"] = r_ank_s

    cap.release()

    used = max(frames_used, 1)
    hips_ratio = frames_with_hips / used
    ankles_ratio = frames_with_ankles / used
    knees_ratio = frames_with_knees / used
    mode = "full_body" if (ankles_ratio >= 0.35 or knees_ratio >= 0.45) else "upper_body"

    if frames_used == 0:
        if sampled_frames > 0:
            return empty_result(fps, mode, "Im Video wurden keine verwertbaren Pose-Landmarks gefunden (leer/zu dunkel/Person nicht sichtbar).")
        return empty_result(fps, mode, "Keine Frames gelesen oder Video ungueltig.")

    amp_l = gesture_amplitude(l_wri_xy)
    amp_r = gesture_amplitude(r_wri_xy)
    gesture_amp = float(max(amp_l, amp_r))

    fidget_ratio = safe_mean(fidget_flags)
    pointing_ratio = safe_mean(pointing_like)
    explain_ratio = safe_mean(explain_point_like)
    arms_cross_ratio = safe_mean(arms_cross_like)

    metrics = {
        "fps": float(fps),
        "frames_used": int(frames_used),
        "coverage": {"hips_ratio": float(hips_ratio), "knees_ratio": float(knees_ratio), "ankles_ratio": float(ankles_ratio), "mode": mode},
        "torso_angle_deg_med": safe_median(torso_angle),
        "shoulder_hip_ratio_med": safe_median(shoulder_hip_ratio),

        "mid_shoulder_speed_p70": safe_percentile(mid_sh_speed, 70),
        "torso_sway_p70": safe_percentile(torso_sway, 70),
        "head_speed_p70": safe_percentile(head_speed, 70),

        "wrist_speed_p70": safe_percentile(wrist_speed, 70),
        "hand_balance_med": safe_median(hand_balance),

        "arms_cross_ratio": float(arms_cross_ratio),
        "foot_speed_p70": safe_percentile(foot_speed, 70),

        "gesture_amplitude": float(gesture_amp),
        "fidget_ratio": float(fidget_ratio),
        "pointing_ratio": float(pointing_ratio),
        "explain_ratio": float(explain_ratio),
    }

    labels = {
        "P1_upright": "Aufrechte Haltung",
        "P2_shoulders_open": "Offene Schultern/Brust",
        "S1_shoulder_stability": "Schultern ruhig (nicht dauernd wippen)",
        "S2_torso_stability": "Körpermitte ruhig",
        "H1_head_stability": "Kopf ruhig",
        "C1_gestures_good": "Gesten wirken sinnvoll (Erklären/Zeigen)",
        "C2_fidgeting": "Wenig nervöses Rumfummeln",
        "C3_arms_open": "Arme eher offen",
        "S3_hand_balance": "Hand-Balance",
        "B1_stance": "Stand (nur wenn sichtbar)",
    }

    # weights: Kopf/Schultern etwas wichtiger, damit schlechte Videos nicht zu hoch bleiben
    max_map = {
        "P1_upright": 16,
        "P2_shoulders_open": 8,
        "S1_shoulder_stability": 16,
        "S2_torso_stability": 12,
        "H1_head_stability": 12,
        "C1_gestures_good": 14,
        "C2_fidgeting": 8,
        "C3_arms_open": 6,
        "S3_hand_balance": 4,
        "B1_stance": 4,
    }

    subscores = {}

    if hips_ratio >= 0.45 and metrics["torso_angle_deg_med"] > 0:
        subscores["P1_upright"] = int(round(score_smaller_is_better(metrics["torso_angle_deg_med"], 14.0, 34.0) * max_map["P1_upright"]))
    if hips_ratio >= 0.45 and metrics["shoulder_hip_ratio_med"] > 0:
        subscores["P2_shoulders_open"] = int(round(score_bigger_is_better(metrics["shoulder_hip_ratio_med"], 0.82, 1.00) * max_map["P2_shoulders_open"]))

    subscores["S1_shoulder_stability"] = int(round(score_smaller_is_better(metrics["mid_shoulder_speed_p70"], 0.22, 1.25) * max_map["S1_shoulder_stability"]))
    subscores["S2_torso_stability"] = int(round(score_smaller_is_better(metrics["torso_sway_p70"], 0.22, 1.15) * max_map["S2_torso_stability"]))
    subscores["H1_head_stability"] = int(round(score_smaller_is_better(metrics["head_speed_p70"], 0.18, 0.95) * max_map["H1_head_stability"]))

    ga = metrics["gesture_amplitude"]
    pr = metrics["pointing_ratio"]
    er = metrics["explain_ratio"]
    if (er >= 0.35) or (pr >= 0.20) or (0.05 <= ga <= 0.35):
        C1 = max_map["C1_gestures_good"]
    elif ga < 0.04 and er < 0.20:
        C1 = int(round(max_map["C1_gestures_good"] * 0.45))
    else:
        C1 = int(round(max_map["C1_gestures_good"] * 0.75))
    subscores["C1_gestures_good"] = int(round(C1))

    fr = metrics["fidget_ratio"]
    if er >= 0.55: fr *= 0.55
    elif er >= 0.35: fr *= 0.75

    if fr <= 0.18: C2 = max_map["C2_fidgeting"]
    elif fr <= 0.30: C2 = int(round(max_map["C2_fidgeting"] * 0.8))
    elif fr <= 0.45: C2 = int(round(max_map["C2_fidgeting"] * 0.55))
    elif fr <= 0.60: C2 = int(round(max_map["C2_fidgeting"] * 0.35))
    else: C2 = int(round(max_map["C2_fidgeting"] * 0.2))
    subscores["C2_fidgeting"] = int(round(C2))

    acr = metrics["arms_cross_ratio"]
    if acr <= 0.15: C3 = max_map["C3_arms_open"]
    elif acr <= 0.35: C3 = int(round(max_map["C3_arms_open"] * 0.75))
    elif acr <= 0.55: C3 = int(round(max_map["C3_arms_open"] * 0.55))
    elif acr <= 0.75: C3 = int(round(max_map["C3_arms_open"] * 0.35))
    else: C3 = 0
    subscores["C3_arms_open"] = int(round(C3))

    subscores["S3_hand_balance"] = int(round(score_smaller_is_better(metrics["hand_balance_med"], 0.35, 0.95) * max_map["S3_hand_balance"]))

    if mode == "full_body":
        subscores["B1_stance"] = int(round(score_smaller_is_better(metrics["foot_speed_p70"], 0.18, 0.90) * max_map["B1_stance"]))

    raw_total = sum(subscores.values())
    max_total = sum(max_map[k] for k in subscores.keys() if k in max_map)
    total = int(round((raw_total / (max_total + 1e-9)) * 100.0))
    total = int(clamp(total, 0, 100))

    scored = []
    for k, v in subscores.items():
        denom = max_map.get(k, 1)
        scored.append((k, v, v / (denom + 1e-9)))
    scored.sort(key=lambda x: x[2])
    worst = scored[:3]
    best = scored[-3:][::-1]

    plus_points = [{"metric": k, "label": labels.get(k, k), "score": int(v)} for k, v, _ in best]
    minus_points = [{"metric": k, "label": labels.get(k, k), "score": int(v)} for k, v, _ in worst]

    best1 = labels.get(best[0][0], best[0][0]) if best else "—"
    worst1 = labels.get(worst[0][0], worst[0][0]) if worst else "—"

    # NEW: verdict tied to total (no more always "Sehr stabil")
    if total >= 90:
        verdict = f"Sehr stabil – {best1} wirkt richtig souverän; nur {worst1} noch ein bisschen glätten."
    elif total >= 75:
        verdict = f"Gut unterwegs – {best1} passt; wenn {worst1} ruhiger wird, wirkt’s noch cleaner."
    elif total >= 55:
        verdict = f"Durchwachsen – {best1} ist okay, aber {worst1} zieht’s noch runter."
    else:
        verdict = f"Eher wacklig – Fokus auf {worst1}. {best1} ist schon ein guter Start."

    return {
        "verdict": verdict,
        "total_score_0_100": total,
        "mode": mode,
        "subscores": subscores,
        "metrics": metrics,
        "plus_points": plus_points,
        "minus_points": minus_points
    }

def build_gpt_payload(result):
    return {"task": "Generate presentation body-language feedback in German.", "analysis": result}

if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("video", help="Path to input video")
    parser.add_argument("--sample", type=int, default=6)
    parser.add_argument("--max_seconds", type=int, default=35)
    args = parser.parse_args()

    res = analyze_video(args.video, sample_every_n_frames=args.sample, max_seconds=args.max_seconds)

    print("\n=== SCORE RESULT ===")
    print(json.dumps(res, indent=2, ensure_ascii=False))

    payload = build_gpt_payload(res)
    print("\n=== GPT PAYLOAD (paste into your LLM call) ===")
    print(json.dumps(payload, indent=2, ensure_ascii=False))
