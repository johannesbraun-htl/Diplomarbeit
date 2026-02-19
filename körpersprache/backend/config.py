from pydantic_settings import BaseSettings
from pathlib import Path

class Settings(BaseSettings):
    OPENAI_API_KEY: str
    BACKEND_HOST: str = "127.0.0.1"
    BACKEND_PORT: int = 8000
    MAX_FILE_SIZE_GB: int = 3
    CHUNK_SIZE_MB: int = 50
    STORAGE_BASE: str = "../storage"
    
    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"

    @property
    def max_file_size_bytes(self) -> int:
        return self.MAX_FILE_SIZE_GB * 1024 * 1024 * 1024
    
    @property
    def chunk_size_bytes(self) -> int:
        return self.CHUNK_SIZE_MB * 1024 * 1024
    
    @property
    def uploads_dir(self) -> Path:
        path = Path(__file__).parent / self.STORAGE_BASE / "uploads"
        path.mkdir(parents=True, exist_ok=True)
        return path
    
    @property
    def chunks_dir(self) -> Path:
        path = Path(__file__).parent / self.STORAGE_BASE / "chunks"
        path.mkdir(parents=True, exist_ok=True)
        return path
    
    @property
    def results_dir(self) -> Path:
        path = Path(__file__).parent / self.STORAGE_BASE / "results"
        path.mkdir(parents=True, exist_ok=True)
        return path

settings = Settings()