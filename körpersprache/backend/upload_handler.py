import uuid
import shutil
from pathlib import Path
from typing import Dict
from fastapi import UploadFile, HTTPException
from config import settings

class UploadHandler:
    def __init__(self):
        self.active_uploads: Dict[str, dict] = {}
    
    def init_upload(self, filename: str, filesize_bytes: int) -> dict:
        # Validierung
        if filesize_bytes > settings.max_file_size_bytes:
            raise HTTPException(
                status_code=400,
                detail=f"Datei zu groß. Maximum: {settings.MAX_FILE_SIZE_GB} GB"
            )
        
        if not filename.lower().endswith(('.mp4', '.mov', '.avi', '.mkv', '.webm')):
            raise HTTPException(status_code=400, detail="Ungültiges Videoformat")
        
        upload_id = str(uuid.uuid4())
        chunk_dir = settings.chunks_dir / upload_id
        chunk_dir.mkdir(parents=True, exist_ok=True)
        
        self.active_uploads[upload_id] = {
            "filename": filename,
            "filesize_bytes": filesize_bytes,
            "chunk_dir": chunk_dir,
            "received_chunks": set()
        }
        
        return {
            "upload_id": upload_id,
            "chunk_size_bytes": settings.chunk_size_bytes
        }
    
    async def receive_chunk(
        self,
        upload_id: str,
        chunk_index: int,
        total_chunks: int,
        chunk_file: UploadFile
    ) -> dict:
        if upload_id not in self.active_uploads:
            raise HTTPException(status_code=404, detail="Upload ID nicht gefunden")
        
        upload_info = self.active_uploads[upload_id]
        chunk_path = upload_info["chunk_dir"] / f"chunk_{chunk_index:05d}"
        
        # Chunk auf Disk schreiben (streaming, kein RAM-Overflow)
        with open(chunk_path, "wb") as f:
            while True:
                chunk_data = await chunk_file.read(8192)  # 8KB Blöcke
                if not chunk_data:
                    break
                f.write(chunk_data)
        
        upload_info["received_chunks"].add(chunk_index)
        
        return {"ok": True}
    
    def complete_upload(self, upload_id: str) -> str:
        if upload_id not in self.active_uploads:
            raise HTTPException(status_code=404, detail="Upload ID nicht gefunden")
        
        upload_info = self.active_uploads[upload_id]
        chunk_dir = upload_info["chunk_dir"]
        
        # Finale Datei zusammensetzen
        final_filename = f"{upload_id}_{upload_info['filename']}"
        final_path = settings.uploads_dir / final_filename
        
        # Chunks sortiert zusammenfügen
        chunk_files = sorted(chunk_dir.glob("chunk_*"))
        
        with open(final_path, "wb") as outfile:
            for chunk_file in chunk_files:
                with open(chunk_file, "rb") as infile:
                    shutil.copyfileobj(infile, outfile, length=1024*1024)  # 1MB Buffer
        
        # Chunks aufräumen
        shutil.rmtree(chunk_dir, ignore_errors=True)
        del self.active_uploads[upload_id]
        
        return str(final_path)

upload_handler = UploadHandler()