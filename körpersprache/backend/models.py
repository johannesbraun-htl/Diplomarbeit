from pydantic import BaseModel, Field
from typing import List, Dict, Any, Optional
from datetime import datetime

class UploadInitRequest(BaseModel):
    filename: str
    filesize_bytes: int

class UploadInitResponse(BaseModel):
    upload_id: str
    chunk_size_bytes: int

class UploadCompleteRequest(BaseModel):
    upload_id: str

class UploadCompleteResponse(BaseModel):
    video_path: str

class AnalyzeRequest(BaseModel):
    upload_id: str
    session_id: Optional[str] = None

class TimeSegment(BaseModel):
    start_sec: float
    end_sec: float
    label: str

class TimestampEvent(BaseModel):
    """Einzelnes Ereignis mit Zeitstempel"""
    timestamp_sec: float
    event_type: str  # Siehe EVENT_TYPES unten
    severity: str  # "positive", "negative", "neutral", "critical"
    description: str
    score: Optional[int] = None  # Optional: Score für diesen Moment

# Event-Types:
# POSITIVE: "excellent_gaze", "perfect_posture", "good_phase"
# NEGATIVE: "gaze_away", "no_face", "excessive_movement", "poor_posture"
# NEUTRAL: "status_check", "phase_start"

class KeyPoints(BaseModel):
    metrics: Dict[str, Any]
    highlights: List[str]
    time_segments: List[TimeSegment]
    timestamp_events: List[TimestampEvent] = []

class ErrorInfo(BaseModel):
    stage: str
    message: str

class AnalysisResult(BaseModel):
    job_id: str
    created_at: str
    overall_score: int = Field(ge=0, le=100)
    audience_gaze_percent: int = Field(ge=0, le=100)
    positive_points: List[str]
    negative_points: List[str]
    improvement_suggestions: List[str]
    keypoints_used: KeyPoints
    errors: List[ErrorInfo] = []

class AnalyzeResponse(BaseModel):
    job_id: str
    status: str
    result_url: str