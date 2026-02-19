// ===================================
// KONFIGURATION (zentral änderbar)
// ===================================
const API_BASE_URL = 'http://127.0.0.1:8000';

// ===================================
// DOM-Elemente
// ===================================
const videoInput = document.getElementById('video-input');
const uploadBtn = document.getElementById('upload-btn');
const uploadSection = document.getElementById('upload-section');
const uploadProgress = document.getElementById('upload-progress');
const progressFill = document.getElementById('progress-fill');
const progressText = document.getElementById('progress-text');
const analysisSection = document.getElementById('analysis-section');
const resultsSection = document.getElementById('results-section');
const errorSection = document.getElementById('error-section');
const errorMessage = document.getElementById('error-message');
const newAnalysisBtn = document.getElementById('new-analysis-btn');
const retryBtn = document.getElementById('retry-btn');

// Ergebnis-Elemente
const overallScore = document.getElementById('overall-score');
const gazePercent = document.getElementById('gaze-percent');
const positiveList = document.getElementById('positive-list');
const negativeList = document.getElementById('negative-list');
const suggestionsList = document.getElementById('suggestions-list');

// ===================================
// Event Listeners
// ===================================
uploadBtn.addEventListener('click', handleUpload);
newAnalysisBtn.addEventListener('click', resetApp);
retryBtn.addEventListener('click', resetApp);

// ===================================
// Haupt-Upload-Handler
// ===================================
async function handleUpload() {
    const file = videoInput.files[0];
    
    if (!file) {
        showError('Bitte wähle zuerst ein Video aus.');
        return;
    }
    
    // Validierung
    const maxSizeGB = 3;
    const maxSizeBytes = maxSizeGB * 1024 * 1024 * 1024;
    
    if (file.size > maxSizeBytes) {
        showError(`Video zu groß. Maximum: ${maxSizeGB} GB`);
        return;
    }
    
    // UI vorbereiten
    uploadBtn.disabled = true;
    uploadProgress.style.display = 'block';
    
    try {
        // 1) Upload-Init
        const initResponse = await fetch(`${API_BASE_URL}/api/upload/init`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                filename: file.name,
                filesize_bytes: file.size
            })
        });
        
        if (!initResponse.ok) {
            const errData = await initResponse.json();
            throw new Error(errData.detail || 'Upload-Init fehlgeschlagen');
        }
        
        const { upload_id, chunk_size_bytes } = await initResponse.json();
        
        // 2) Chunked Upload
        await uploadFileInChunks(file, upload_id, chunk_size_bytes);
        
        // 3) Upload Complete
        const completeResponse = await fetch(`${API_BASE_URL}/api/upload/complete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ upload_id })
        });
        
        if (!completeResponse.ok) {
            throw new Error('Upload-Complete fehlgeschlagen');
        }
        
        // 4) Analyse starten
        await startAnalysis(upload_id);
        
    } catch (error) {
        showError(`Fehler: ${error.message}`);
        uploadBtn.disabled = false;
        uploadProgress.style.display = 'none';
    }
}

// ===================================
// Chunked Upload
// ===================================
async function uploadFileInChunks(file, uploadId, chunkSize) {
    const totalChunks = Math.ceil(file.size / chunkSize);
    
    for (let i = 0; i < totalChunks; i++) {
        const start = i * chunkSize;
        const end = Math.min(start + chunkSize, file.size);
        const chunk = file.slice(start, end);
        
        // FormData für Chunk
        const formData = new FormData();
        formData.append('upload_id', uploadId);
        formData.append('chunk_index', i);
        formData.append('total_chunks', totalChunks);
        formData.append('file', chunk);
        
        // Chunk hochladen
        const response = await fetch(`${API_BASE_URL}/api/upload/chunk`, {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`Chunk ${i + 1}/${totalChunks} fehlgeschlagen`);
        }
        
        // Fortschritt aktualisieren
        const progress = Math.round(((i + 1) / totalChunks) * 100);
        updateProgress(progress);
    }
}

// ===================================
// Analyse starten
// ===================================
async function startAnalysis(uploadId) {
    // UI umschalten
    uploadSection.style.display = 'none';
    analysisSection.style.display = 'block';
    
    try {
        const response = await fetch(`${API_BASE_URL}/api/analyze`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ upload_id: uploadId })
        });
        
        if (!response.ok) {
            throw new Error('Analyse-Start fehlgeschlagen');
        }
        
        const { job_id, status } = await response.json();
        
        if (status === 'done' || status === 'error') {
            await fetchResult(job_id);
        } else {
            await pollResult(job_id);
        }
        
    } catch (error) {
        showError(`Analyse-Fehler: ${error.message}`);
    }
}

// ===================================
// Ergebnis abrufen
// ===================================
async function fetchResult(jobId) {
    try {
        const response = await fetch(`${API_BASE_URL}/api/result/${jobId}`);
        
        if (!response.ok) {
            throw new Error('Ergebnis nicht gefunden');
        }
        
        const result = await response.json();
        
        if (result.errors && result.errors.length > 0) {
            showError(`Analyse fehlgeschlagen: ${result.errors[0].message}`);
        } else {
            showResults(result);
        }
        
    } catch (error) {
        showError(`Fehler beim Abrufen: ${error.message}`);
    }
}

// ===================================
// Polling (falls nötig)
// ===================================
async function pollResult(jobId) {
    const maxAttempts = 60;
    let attempts = 0;
    
    const poll = async () => {
        if (attempts >= maxAttempts) {
            showError('Timeout: Analyse dauert zu lange');
            return;
        }
        
        try {
            const response = await fetch(`${API_BASE_URL}/api/result/${jobId}`);
            
            if (response.ok) {
                const result = await response.json();
                showResults(result);
            } else {
                attempts++;
                setTimeout(poll, 2000);
            }
        } catch (error) {
            attempts++;
            setTimeout(poll, 2000);
        }
    };
    
    poll();
}

// ===================================
// UI: Fortschritt aktualisieren
// ===================================
function updateProgress(percent) {
    progressFill.style.width = `${percent}%`;
    progressText.textContent = `${percent}%`;
}

// ===================================
// UI: Ergebnisse anzeigen
// ===================================
function showResults(result) {
    analysisSection.style.display = 'none';
    resultsSection.style.display = 'block';
    
    // Scores
    overallScore.textContent = result.overall_score;
    gazePercent.textContent = result.audience_gaze_percent;
    
    // Positive Punkte
    positiveList.innerHTML = '';
    result.positive_points.forEach(point => {
        const li = document.createElement('li');
        li.textContent = point;
        positiveList.appendChild(li);
    });
    
    // Negative Punkte
    negativeList.innerHTML = '';
    result.negative_points.forEach(point => {
        const li = document.createElement('li');
        li.textContent = point;
        negativeList.appendChild(li);
    });
    
    // Verbesserungsvorschläge
    suggestionsList.innerHTML = '';
    result.improvement_suggestions.forEach(suggestion => {
        const li = document.createElement('li');
        li.textContent = suggestion;
        suggestionsList.appendChild(li);
    });
    
    // Debug-Video laden
    const debugVideoElement = document.getElementById('debug-video');
    const debugVideoSrc = document.getElementById('debug-video-src');
    const debugVideoDownload = document.getElementById('debug-video-download');
    const debugVideoUrl = `${API_BASE_URL}/api/debug-video/${result.job_id}`;
    
    debugVideoSrc.src = debugVideoUrl;
    debugVideoDownload.href = debugVideoUrl;
    debugVideoDownload.download = `debug_${result.job_id}.mp4`;
    
    // Video neu laden
    debugVideoElement.load();
    
    // Timestamps rendern
    renderTimestamps(result.keypoints_used.timestamp_events, debugVideoElement);
}

// ===================================
// Timestamp-Rendering
// ===================================
function renderTimestamps(events, videoElement) {
    const timestampList = document.getElementById('timestamp-list');
    const timestampSection = document.getElementById('timestamp-section');
    
    if (!events || events.length === 0) {
        timestampSection.style.display = 'none';
        return;
    }
    
    timestampSection.style.display = 'block';
    timestampList.innerHTML = '';
    
    events.forEach(event => {
        const item = document.createElement('div');
        item.className = `timestamp-item ${event.severity}`;
        
        // Icon basierend auf Event-Type
        const icon = getEventIcon(event.event_type);
        
        // Zeit formatieren
        const timeFormatted = formatTimestamp(event.timestamp_sec);
        
        // Score optional anzeigen
        const scoreHtml = event.score !== null && event.score !== undefined 
            ? `<span class="timestamp-score">${event.score}%</span>` 
            : '';
        
        item.innerHTML = `
            <span class="timestamp-icon">${icon}</span>
            <span class="timestamp-time">${timeFormatted}</span>
            <span class="timestamp-description">${event.description}</span>
            ${scoreHtml}
            <span class="timestamp-badge ${event.severity}">${getSeverityLabel(event.severity)}</span>
        `;
        
        // Click-Handler
        item.addEventListener('click', () => {
            videoElement.currentTime = event.timestamp_sec;
            videoElement.play();
            
            videoElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Visual Feedback
            const originalBg = item.style.background;
            const originalColor = item.style.color;
            
            if (event.severity === 'positive') {
                item.style.background = '#2e7d32';
            } else if (event.severity === 'negative' || event.severity === 'critical') {
                item.style.background = '#d32f2f';
            } else {
                item.style.background = '#667eea';
            }
            
            item.style.color = 'white';
            
            setTimeout(() => {
                item.style.background = originalBg;
                item.style.color = originalColor;
            }, 300);
        });
        
        timestampList.appendChild(item);
    });
}

function getEventIcon(eventType) {
    const icons = {
        // Negative
        'gaze_away': '👁️❌',
        'gaze_away_long': '👁️❌❌',
        'no_face': '😶',
        'excessive_movement': '🏃💨',
        
        // Positive
        'excellent_gaze': '👁️✨',
        'good_phase': '⭐',
        'perfect_posture': '🧍✅',
        
        // Neutral
        'status_check': '📊',
        'phase_start': '🎬',
        'weak_phase': '📉'
    };
    return icons[eventType] || '⚠️';
}

function getSeverityLabel(severity) {
    const labels = {
        'positive': 'Gut',
        'neutral': 'Info',
        'negative': 'Warnung',
        'critical': 'Kritisch'
    };
    return labels[severity] || severity;
}

// ===================================
// UI: Fehler anzeigen
// ===================================
function showError(message) {
    uploadSection.style.display = 'none';
    analysisSection.style.display = 'none';
    resultsSection.style.display = 'none';
    errorSection.style.display = 'block';
    
    errorMessage.textContent = message;
}

// ===================================
// UI: App zurücksetzen
// ===================================
function resetApp() {
    uploadSection.style.display = 'block';
    analysisSection.style.display = 'none';
    resultsSection.style.display = 'none';
    errorSection.style.display = 'none';
    
    uploadProgress.style.display = 'none';
    progressFill.style.width = '0%';
    progressText.textContent = '0%';
    
    uploadBtn.disabled = false;
    videoInput.value = '';
}






// ===========================================
// Mache die Timestamps nur bei Keyponts (z.B. etwas sehr Positvem und gib dort die genaue zeit an von wann bis wann oder eben auch bei negativen sachen) und es sollten mehr wie nur ein positiver und 1 negativer punkt sein aßer 
// natürlich es gab nur eine positive und eine negative sachen aber es sollte so zwischen 1-3 jeweils sein eben die wichtigsten aspekte, dann baue das nun in dieses Projekt ein und es soll es auf die DB laden und beim Projekt auch 
// sachen abändern dass es auch mit so api und dem allem funktioniert und das Projekt robuster machen und dann alle geänderten Dateien den vollständigen code hochladen.
// ===========================================