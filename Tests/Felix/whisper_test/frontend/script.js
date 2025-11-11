class WhisperTranscriber {
    constructor() {
        this.isRecording = false;
        this.mediaRecorder = null;
        this.audioChunks = [];
        this.backendUrl = 'http://localhost:5000';
        this.recordingInterval = null;
        this.fillerStats = {
            total: 0,
            byType: {},
            sessions: []
        };
        this.speechTempo = {
            currentWpm: 0,
            history: []
        };
        
        this.initializeElements();
        this.setupEventListeners();
        this.initializeStats();
    }
    
    initializeElements() {
        this.startBtn = document.getElementById('startBtn');
        this.stopBtn = document.getElementById('stopBtn');
        this.status = document.getElementById('status');
        this.transcript = document.getElementById('transcript');
        this.fillerStatsElement = document.getElementById('fillerStats');
        this.wpmValue = document.getElementById('wpmValue');
        this.wpmFeedback = document.getElementById('wpmFeedback');
        this.wpmProgress = document.getElementById('wpmProgress');
        this.confidenceBadge = document.getElementById('confidenceBadge');
    }
    
    setupEventListeners() {
        this.startBtn.addEventListener('click', () => this.startRecording());
        this.stopBtn.addEventListener('click', () => this.stopRecording());
    }
    
    initializeStats() {
        this.fillerStats = {
            total: 0,
            byType: {},
            sessions: [],
            startTime: null
        };
        this.speechTempo = {
            currentWpm: 0,
            history: []
        };
        this.updateFillerStatsDisplay();
        this.updateWpmDisplay();
    }
    
    async startRecording() {
        try {
            this.updateStatus('🔍 Starte Aufnahme...', 'info');
            this.initializeStats();
            this.fillerStats.startTime = new Date();
            
            const constraints = {
                audio: {
                    sampleRate: 16000,
                    channelCount: 1,
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                    latency: 0,
                    sampleSize: 16,
                    volume: 1.0
                },
                video: false
            };
            
            const stream = await navigator.mediaDevices.getUserMedia(constraints);
            
            this.mediaRecorder = new MediaRecorder(stream, {
                mimeType: 'audio/webm;codecs=opus',
                audioBitsPerSecond: 128000
            });
            
            this.audioChunks = [];
            
            this.mediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    this.audioChunks.push(event.data);
                    this.sendAudioToBackend(event.data);
                }
            };
            
            this.mediaRecorder.start();
            
            this.recordingInterval = setInterval(() => {
                if (this.isRecording && this.mediaRecorder.state === 'recording') {
                    this.mediaRecorder.stop();
                    this.mediaRecorder.start();
                    this.audioChunks = [];
                }
            }, 4000);
            
            this.isRecording = true;
            
            this.updateUI('recording');
            this.updateStatus('🎤 Aufnahme läuft - Analysiere Sprache...', 'recording');
            
        } catch (error) {
            console.error('Mikrofon-Fehler:', error);
            this.updateStatus('❌ Mikrofon-Fehler: ' + error.message, 'error');
        }
    }
    
    stopRecording() {
        if (this.mediaRecorder && this.isRecording) {
            clearInterval(this.recordingInterval);
            this.mediaRecorder.stop();
            this.isRecording = false;
            
            this.mediaRecorder.stream.getTracks().forEach(track => track.stop());
            
            this.updateUI('stopped');
            this.updateStatus('✅ Aufnahme beendet - Analyse verfügbar', 'success');
            
            this.showFinalStats();
        }
    }
    
    async sendAudioToBackend(audioBlob) {
        try {
            this.updateStatus('📡 Analysiere Sprache...', 'processing');
            
            const reader = new FileReader();
            
            reader.onload = async () => {
                try {
                    const base64Audio = reader.result;
                    
                    const response = await fetch(`${this.backendUrl}/transcribe`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            audio_data: base64Audio
                        })
                    });
                    
                    if (!response.ok) {
                        throw new Error(`Server error: ${response.status}`);
                    }
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        this.updateTranscript(result);
                        this.updateStatus('✅ Analyse erfolgreich', 'success');
                    } else {
                        this.updateStatus('❌ Analyse-Fehler: ' + (result.error || 'Unbekannt'), 'error');
                    }
                    
                } catch (error) {
                    console.error('Verarbeitungsfehler:', error);
                    this.updateStatus('❌ Verarbeitungsfehler: ' + error.message, 'error');
                }
            };
            
            reader.onerror = () => {
                console.error('FileReader Fehler');
                this.updateStatus('❌ Fehler beim Lesen der Audio-Daten', 'error');
            };
            
            reader.readAsDataURL(audioBlob);
            
        } catch (error) {
            console.error('Sendefehler:', error);
            this.updateStatus('❌ Verbindungsfehler: ' + error.message, 'error');
        }
    }
    
    updateTranscript(result) {
        if (!result.marked_text || result.marked_text.length < 2) {
            return;
        }
        
        const timestamp = new Date().toLocaleTimeString();
        const transcriptDiv = document.createElement('div');
        transcriptDiv.className = 'transcript-entry';
        
        // Füllwort-Statistik aktualisieren
        this.updateFillerStatistics(result.filler_analysis);
        
        // WPM-Anzeige aktualisieren
        this.updateWpmDisplay(result.speech_tempo);
        
        // Konfidenz-Anzeige aktualisieren
        this.updateConfidenceBadge(result.confidence);
        
        let confidenceHTML = '';
        if (result.confidence && result.confidence > -5) {
            const confidencePercent = Math.min(100, Math.max(0, (result.confidence + 10) * 10));
            confidenceHTML = ` <span class="confidence-indicator">(${confidencePercent.toFixed(0)}% sicher)</span>`;
        }
        
        // Füllwort-Info für diesen Block
        const fillerInfo = result.filler_analysis.total > 0 ? 
            `<div class="filler-warning">
                ⚠️ ${result.filler_analysis.total} Füllwort${result.filler_analysis.total > 1 ? 'er' : ''} erkannt
            </div>` : '';
        
        transcriptDiv.innerHTML = `
            <div class="transcript-content">
                <div class="transcript-header">
                    <strong class="timestamp">${timestamp}</strong>
                    ${confidenceHTML}
                </div>
                <div class="transcript-text">${result.marked_text}</div>
                ${fillerInfo}
            </div>
        `;
        
        // Neuen Eintrag am Anfang einfügen
        this.transcript.insertBefore(transcriptDiv, this.transcript.firstChild);
        
        // Alte Einträge löschen
        this.cleanupOldEntries();
        
        // Smooth Scroll
        transcriptDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    
    updateWpmDisplay(speechTempo) {
        if (!speechTempo) {
            this.wpmValue.textContent = '-';
            this.wpmFeedback.textContent = 'Starte die Aufnahme für WPM-Analyse';
            this.wpmProgress.style.width = '0%';
            this.wpmProgress.style.backgroundColor = '#6c757d';
            return;
        }
        
        const wpm = speechTempo.words_per_minute;
        this.wpmValue.textContent = wpm;
        this.wpmValue.style.color = speechTempo.color;
        this.wpmFeedback.textContent = speechTempo.feedback;
        this.wpmFeedback.style.color = speechTempo.color;
        
        // WPM Progress Bar (0-250 WPM Skala)
        const progressPercent = Math.min(100, (wpm / 250) * 100);
        this.wpmProgress.style.width = `${progressPercent}%`;
        this.wpmProgress.style.backgroundColor = speechTempo.color;
        
        // Zur History hinzufügen
        this.speechTempo.history.push({
            wpm: wpm,
            timestamp: new Date(),
            rating: speechTempo.rating
        });
    }
    
    updateConfidenceBadge(confidence) {
        if (!confidence || confidence <= -5) {
            this.confidenceBadge.style.display = 'none';
            return;
        }
        
        const confidencePercent = Math.min(100, Math.max(0, (confidence + 10) * 10));
        this.confidenceBadge.querySelector('.confidence-value').textContent = 
            confidencePercent.toFixed(0);
        
        // Farbe basierend auf Konfidenz
        if (confidencePercent >= 80) {
            this.confidenceBadge.style.background = 'linear-gradient(135deg, #28a745, #20c997)';
        } else if (confidencePercent >= 60) {
            this.confidenceBadge.style.background = 'linear-gradient(135deg, #ffc107, #fd7e14)';
        } else {
            this.confidenceBadge.style.background = 'linear-gradient(135deg, #dc3545, #e83e8c)';
        }
        
        this.confidenceBadge.style.display = 'flex';
    }
    
    updateFillerStatistics(fillerAnalysis) {
        // Gesamtstatistik aktualisieren
        this.fillerStats.total += fillerAnalysis.total;
        
        // Füllwörter nach Typ zählen
        for (const [filler, count] of Object.entries(fillerAnalysis.count)) {
            this.fillerStats.byType[filler] = (this.fillerStats.byType[filler] || 0) + count;
        }
        
        // Session speichern
        this.fillerStats.sessions.push({
            timestamp: new Date().toISOString(),
            analysis: fillerAnalysis
        });
        
        this.updateFillerStatsDisplay();
    }
    
    updateFillerStatsDisplay() {
        if (!this.fillerStatsElement) return;
        
        const totalWords = this.fillerStats.sessions.reduce((sum, session) => 
            sum + (session.analysis.total_words || 0), 0);
        
        const fillerPercentage = totalWords > 0 ? 
            (this.fillerStats.total / totalWords * 100).toFixed(1) : '0.0';
        
        // Top Füllwörter finden
        const topFillers = Object.entries(this.fillerStats.byType)
            .sort(([,a], [,b]) => b - a)
            .slice(0, 5);
        
        if (topFillers.length === 0) {
            this.fillerStatsElement.innerHTML = `
                <div class="stats-placeholder">
                    <div class="placeholder-icon">📈</div>
                    <p>Starte die Aufnahme um Statistiken zu sehen</p>
                </div>
            `;
            return;
        }
        
        this.fillerStatsElement.innerHTML = `
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number" style="color: #e74c3c;">${this.fillerStats.total}</div>
                    <div class="stat-label">Füllwörter gesamt</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #f39c12;">${fillerPercentage}%</div>
                    <div class="stat-label">Anteil an Wörtern</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #3498db;">${topFillers.length}</div>
                    <div class="stat-label">Verschiedene Arten</div>
                </div>
            </div>
            
            <div class="top-fillers">
                <h4>🏆 Meist verwendete Füllwörter:</h4>
                <div class="filler-list">
                    ${topFillers.map(([filler, count]) => `
                        <div class="filler-item">
                            <span class="filler-word">${filler}</span>
                            <span class="filler-count">${count}x</span>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }
    
    cleanupOldEntries() {
        const entries = this.transcript.querySelectorAll('.transcript-entry');
        if (entries.length > 8) {
            for (let i = entries.length - 1; i >= 8; i--) {
                if (entries[i] && entries[i].parentNode === this.transcript) {
                    this.transcript.removeChild(entries[i]);
                }
            }
        }
    }
    
    showFinalStats() {
        if (this.fillerStats.total > 0) {
            const totalWords = this.fillerStats.sessions.reduce((sum, session) => 
                sum + (session.analysis.total_words || 0), 0);
            
            const fillerPercentage = totalWords > 0 ? 
                (this.fillerStats.total / totalWords * 100).toFixed(1) : '0.0';
            
            const duration = this.fillerStats.startTime ? 
                Math.round((new Date() - this.fillerStats.startTime) / 1000) : 0;
            
            const avgWpm = this.speechTempo.history.length > 0 ? 
                (this.speechTempo.history.reduce((sum, entry) => sum + entry.wpm, 0) / this.speechTempo.history.length).toFixed(1) : 0;
            
            setTimeout(() => {
                alert(`🎯 Analyse abgeschlossen!\n\n` +
                      `📊 Sprachstatistik:\n` +
                      `• ${this.fillerStats.total} Füllwörter erkannt\n` +
                      `• ${fillerPercentage}% aller Wörter waren Füllwörter\n` +
                      `• Durchschnittlich ${avgWpm} Wörter pro Minute\n` +
                      `• Dauer: ${duration} Sekunden\n\n` +
                      `💡 Tipp: Versuchen Sie, bewusst Pausen zu machen statt Füllwörter zu verwenden!`);
            }, 500);
        }
    }
    
    updateStatus(message, type = 'info') {
        const statusContent = this.status.querySelector('.status-content');
        const statusIcon = this.status.querySelector('.status-icon');
        const statusText = this.status.querySelector('.status-text');
        
        statusText.textContent = message;
        
        // Icons und Farben basierend auf Typ
        const statusConfig = {
            info: { icon: '⚡', color: '#6c757d' },
            recording: { icon: '🎤', color: '#28a745' },
            processing: { icon: '📡', color: '#17a2b8' },
            success: { icon: '✅', color: '#28a745' },
            error: { icon: '❌', color: '#dc3545' }
        };
        
        const config = statusConfig[type] || statusConfig.info;
        statusIcon.textContent = config.icon;
        this.status.style.borderLeftColor = config.color;
    }
    
    updateUI(state) {
        if (state === 'recording') {
            this.startBtn.disabled = true;
            this.stopBtn.disabled = false;
            this.startBtn.classList.add('disabled');
            this.stopBtn.classList.remove('disabled');
        } else {
            this.startBtn.disabled = false;
            this.stopBtn.disabled = true;
            this.startBtn.classList.remove('disabled');
            this.stopBtn.classList.add('disabled');
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new WhisperTranscriber();
    console.log("🎯 PresentAI Sprachanalyse geladen");
});