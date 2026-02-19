@echo off
echo ========================================
echo   Koerpersprache Analyzer - Backend
echo ========================================
echo.

cd /d "%~dp0"

REM Python-Version pruefen
python --version 2>&1 | findstr /C:"3.11" >nul
if %errorlevel%==0 goto :version_ok

python --version 2>&1 | findstr /C:"3.12" >nul
if %errorlevel%==0 goto :version_ok

python --version 2>&1 | findstr /C:"3.13" >nul
if %errorlevel%==0 goto :version_ok

echo WARNUNG: Python 3.11, 3.12 oder 3.13 empfohlen!
echo Deine Version:
python --version
echo.
echo Python 3.14 kann zu Problemen fuehren.
echo.
pause

:version_ok

if not exist .env (
    echo FEHLER: .env Datei fehlt!
    echo Bitte .env.example nach .env kopieren
    pause
    exit /b 1
)

echo Installiere Dependencies...
pip install -r requirements.txt
if errorlevel 1 (
    echo.
    echo Installation fehlgeschlagen!
    pause
    exit /b 1
)

echo.
echo Backend startet auf http://127.0.0.1:8000
echo API Docs: http://127.0.0.1:8000/docs
echo.

python -m uvicorn main:app --host 127.0.0.1 --port 8000 --reload

pause