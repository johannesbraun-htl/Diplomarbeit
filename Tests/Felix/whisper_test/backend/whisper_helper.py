import whisper

class WhisperTranscriber:
    def __init__(self, model_size="base"):
        print("Lade Whisper Modell...")
        self.model = whisper.load_model(model_size)
        print("Whisper Modell geladen!")
    
    def transcribe_audio(self, audio_file_path: str) -> dict:
        try:
            print(f"Transkribiere Datei: {audio_file_path}")
            result = self.model.transcribe(audio_file_path, language="de")
            
            return {
                "text": result["text"],
                "language": result["language"],
                "success": True
            }
        except Exception as e:
            return {
                "text": "",
                "error": str(e),
                "success": False
            }