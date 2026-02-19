from fastapi import FastAPI, UploadFile, File, Form, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse, FileResponse
from pathlib import Path
import json
import uuid
import shutil
from typing import Optional
from datetime import datetime

from config import settings
from models import *
from upload_handler import upload_handler
from analyzer import video_analyzer
from llm_processor import llm_processor

app = FastAPI(title="Körpersprache Analyzer API")

# CORS für XAMPP Frontend
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=False,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Health Check
@app.get("/api/health")
async def health_check():
    return {"status": "ok", "version": "1.0.0"}

# Chunked Upload - Init
@app.post("/api/upload/init", response_model=UploadInitResponse)
async def upload_init(request: UploadInitRequest):
    try:
        result = upload_handler.init_upload(request.filename, request.filesize_bytes)
        return UploadInitResponse(**result)
    except HTTPException as e:
        raise e
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Init-Fehler: {str(e)}")

# Chunked Upload - Chunk empfangen
@app.post("/api/upload/chunk")
async def upload_chunk(
    upload_id: str = Form(...),
    chunk_index: int = Form(...),
    total_chunks: int = Form(...),
    file: UploadFile = File(...)
):
    try:
        result = await upload_handler.receive_chunk(upload_id, chunk_index, total_chunks, file)
        return result
    except HTTPException as e:
        raise e
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Chunk-Fehler: {str(e)}")

# Chunked Upload - Complete
@app.post("/api/upload/complete", response_model=UploadCompleteResponse)
async def upload_complete(request: UploadCompleteRequest):
    try:
        video_path = upload_handler.complete_upload(request.upload_id)
        return UploadCompleteResponse(video_path=video_path)
    except HTTPException as e:
        raise e
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Complete-Fehler: {str(e)}")

# Analyse starten
@app.post("/api/analyze", response_model=AnalyzeResponse)
async def analyze_video(request: AnalyzeRequest):
    job_id = str(uuid.uuid4())
    
    try:
        # Video-Pfad aus upload_id rekonstruieren
        upload_files = list(settings.uploads_dir.glob(f"{request.upload_id}_*"))
        if not upload_files:
            raise HTTPException(status_code=404, detail="Video nicht gefunden")
        
        video_path = str(upload_files[0])
        
        # Stufe 1: Eigene Analyse (MIT DEBUG-VIDEO + TIMESTAMPS)
        keypoints = video_analyzer.analyze_video(video_path, output_debug_video=True)
        
        # Debug-Video kopieren zu results
        debug_video_src = Path(video_path).parent / f"debug_{Path(video_path).name}"
        if debug_video_src.exists():
            debug_video_dst = settings.results_dir / f"debug_{job_id}.mp4"
            shutil.copy(debug_video_src, debug_video_dst)
        
        # Stufe 2: LLM-Analyse
        result = llm_processor.generate_analysis(job_id, keypoints)
        
        # Speichern
        result_path = settings.results_dir / f"{job_id}.json"
        with open(result_path, "w", encoding="utf-8") as f:
            json.dump(result.model_dump(), f, ensure_ascii=False, indent=2)
        
        return AnalyzeResponse(
            job_id=job_id,
            status="done",
            result_url=f"/api/result/{job_id}"
        )
    
    except Exception as e:
        # Fehler speichern
        error_result = AnalysisResult(
            job_id=job_id,
            created_at=datetime.utcnow().isoformat(),
            overall_score=0,
            audience_gaze_percent=0,
            positive_points=[],
            negative_points=["Analyse fehlgeschlagen"],
            improvement_suggestions=[],
            keypoints_used=KeyPoints(metrics={}, highlights=[], time_segments=[], timestamp_events=[]),
            errors=[ErrorInfo(stage="analysis", message=str(e))]
        )
        
        result_path = settings.results_dir / f"{job_id}.json"
        with open(result_path, "w", encoding="utf-8") as f:
            json.dump(error_result.model_dump(), f, ensure_ascii=False, indent=2)
        
        return AnalyzeResponse(
            job_id=job_id,
            status="error",
            result_url=f"/api/result/{job_id}"
        )

# Ergebnis abrufen
@app.get("/api/result/{job_id}")
async def get_result(job_id: str):
    result_path = settings.results_dir / f"{job_id}.json"
    
    if not result_path.exists():
        raise HTTPException(status_code=404, detail="Ergebnis nicht gefunden")
    
    with open(result_path, "r", encoding="utf-8") as f:
        result_data = json.load(f)
    
    return JSONResponse(content=result_data)

# Debug-Video abrufen
@app.get("/api/debug-video/{job_id}")
async def get_debug_video(job_id: str):
    debug_video_path = settings.results_dir / f"debug_{job_id}.mp4"
    
    if not debug_video_path.exists():
        raise HTTPException(status_code=404, detail="Debug-Video nicht gefunden")
    
    return FileResponse(
        debug_video_path,
        media_type="video/mp4",
        filename=f"debug_{job_id}.mp4"
    )

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "main:app",
        host=settings.BACKEND_HOST,
        port=settings.BACKEND_PORT,
        reload=True

    )


