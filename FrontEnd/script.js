/**
 * AEGIS-NODE DASHBOARD - MAIN SCRIPT
 * Version 1.1 - Added Global Remote Unlock
 */

// --- CONFIGURATION ---
const CALLAI_PATH = '/fProject_PEMBEDS%202/BackEnd/CallAI.php'; 
const SENSOR_POLL_PATH = '/fProject_PEMBEDS%202/BackEnd/sensor_fetch.php?device_id=1';
const SENSOR_HISTORY_PATH = '/fProject_PEMBEDS%202/BackEnd/sensor_history.php?device_id=1&limit=50';
const AI_LOGS_PATH = '/fProject_PEMBEDS%202/BackEnd/fetch_ai_logs.php?limit=50';
const COMMAND_PATH = '/fProject_PEMBEDS%202/BackEnd/send_command.php';

let sensorChart = null;

// --- REMOTE ACTIONS (Must be global for HTML onclick) ---

/**
 * Sends the unlock command to the PHP backend
 */
async function remoteUnlock() {
    const pinInput = document.getElementById('web-pin');
    const pin = pinInput.value.trim();
    
    if(!pin) {
        alert("Please enter a PIN.");
        return;
    }

    try {
        const res = await fetch(COMMAND_PATH, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ pin })
        });
        
        const data = await res.json();
        alert(data.message);
        
        // Visual feedback
        if(data.ok) {
            pinInput.value = '';
            fetchSensorStatus(); // Refresh UI immediately
        }
    } catch (err) {
        console.error("Unlock error:", err);
        alert("Connection Error: Could not reach backend.");
    }
}

// --- DATA FETCHING FUNCTIONS ---

async function fetchAILogs() {
    try {
        const res = await fetch(AI_LOGS_PATH);
        if (!res.ok) return;
        const j = await res.json();
        if (!j.ok || !Array.isArray(j.data)) return;
        
        const tbody = document.getElementById('ai-log-body');
        tbody.innerHTML = '';

        for (let row of j.data) {
            const time = (row.created_at) ? new Date(row.created_at).toLocaleString() : '';
            addAILogEntry(time, row.prompt, row.response);
        }
    } catch (err) {
        console.error('fetchAILogs error', err);
    }
}

async function fetchSensorStatus() {
    try {
        const res = await fetch(SENSOR_POLL_PATH);
        if (!res.ok) return;
        const j = await res.json();
        if (!j.ok || !j.status) return;
        
        const s = j.status;
        document.getElementById('dist-val').innerText = (s.distance === null) ? '-- cm' : (s.distance + ' cm');
        document.getElementById('sound-val').innerText = (s.sound_db === null) ? '-- dB' : (s.sound_db + ' dB');
        document.getElementById('pir-status').innerText = s.pir_status || 'STABLE';
        
        // Update Lock State UI
        const lockText = document.getElementById('lock-text');
        const lockIcon = document.getElementById('lock-icon');
        
        lockText.innerText = s.lock_state || 'UNLOCKED';
        lockIcon.innerText = (s.lock_state === 'HARD-LOCK') ? '🔒' : '🔓';
        
        // Dynamic coloring for lock state
        if (s.lock_state === 'HARD-LOCK') {
            lockText.classList.add('text-red-500');
            lockText.classList.remove('text-green-500');
            lockIcon.classList.add('text-red-500');
        } else {
            lockText.classList.add('text-green-500');
            lockText.classList.remove('text-red-500');
            lockIcon.classList.remove('text-red-500');
        }
    } catch (err) {
        console.error('fetchSensorStatus error', err);
    }
}

async function fetchSensorHistoryAndUpdateChart() {
    try {
        const res = await fetch(SENSOR_HISTORY_PATH);
        if (!res.ok) return;
        const j = await res.json();
        if (!j.ok || !j.data) return;

        const rows = j.data;
        const labels = rows.map(r => new Date(r.created_at).toLocaleTimeString());
        const distanceData = rows.map(r => (r.distance === null ? null : parseFloat(r.distance)));
        const soundData = rows.map(r => (r.sound_db === null ? null : parseFloat(r.sound_db)));

        if (!sensorChart) {
            const ctx = document.getElementById('sensorChart').getContext('2d');
            sensorChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Distance (cm)',
                            data: distanceData,
                            yAxisID: 'y1',
                            borderWidth: 2,
                            borderColor: 'rgba(56,189,248,1)',
                            backgroundColor: 'rgba(56,189,248,0.1)',
                            spanGaps: true
                        },
                        {
                            label: 'Sound (raw)',
                            data: soundData,
                            yAxisID: 'y2',
                            borderWidth: 2,
                            borderColor: 'rgba(99,102,241,1)',
                            backgroundColor: 'rgba(99,102,241,0.08)',
                            spanGaps: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        y1: { type: 'linear', position: 'left', beginAtZero: true },
                        y2: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } }
                    }
                }
            });
        } else {
            sensorChart.data.labels = labels;
            sensorChart.data.datasets[0].data = distanceData;
            sensorChart.data.datasets[1].data = soundData;
            sensorChart.update('none'); // Update without animation for performance
        }
    } catch (err) {
        console.error('Chart update error', err);
    }
}

// --- UI HELPERS ---

function updateClock() {
    document.getElementById('clock').innerText = new Date().toLocaleTimeString();
}

function showOverlay() { document.getElementById('ai-overlay').classList.remove('hidden'); }
function hideOverlay() { document.getElementById('ai-overlay').classList.add('hidden'); }

function addAILogEntry(time, prompt, response) {
    const table = document.getElementById('ai-log-body');
    const row = table.insertRow(0);
    row.innerHTML = `
        <td class="p-2 align-top text-xs text-gray-400">${time}</td>
        <td class="p-2 align-top font-mono text-xs" style="max-width:240px;word-wrap:break-word;">${escapeHtml(prompt)}</td>
        <td class="p-2 align-top font-mono text-xs" style="max-width:360px;word-wrap:break-word;">${escapeHtml(response)}</td>
    `;
}

function escapeHtml(unsafe) {
    if (!unsafe && unsafe !== 0) return '';
    return String(unsafe)
         .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function showTab(tab) {
    const isLogs = tab === 'logs';
    document.getElementById('tab-content-logs').classList.toggle('hidden', !isLogs);
    document.getElementById('tab-content-ai').classList.toggle('hidden', isLogs);
    
    document.getElementById('tab-logs').className = isLogs ? 'px-4 py-3 text-sm font-bold text-cyan-300 border-b-2 border-cyan-500' : 'px-4 py-3 text-sm font-medium text-gray-400 hover:text-cyan-300';
    document.getElementById('tab-ai').className = !isLogs ? 'px-4 py-3 text-sm font-bold text-cyan-300 border-b-2 border-cyan-500' : 'px-4 py-3 text-sm font-medium text-gray-400 hover:text-cyan-300';
}

// --- INITIALIZATION ---

document.addEventListener('DOMContentLoaded', () => {
    // Tab switching
    document.getElementById('tab-logs').addEventListener('click', () => showTab('logs'));
    document.getElementById('tab-ai').addEventListener('click', () => showTab('ai'));

    // AI Form Submission
    const aiForm = document.getElementById('ai-form');
    aiForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const prompt = document.getElementById('ai-prompt').value.trim();
        if (!prompt) return;

        showOverlay();
        document.getElementById('ai-response').innerText = '';

        try {
            const res = await fetch(CALLAI_PATH, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({prompt})
            });

            const text = await res.text(); 
            let parsed = null;
            try { parsed = JSON.parse(text); } catch (err) { parsed = null; }

            let responseSummary = '';
            if (parsed && parsed.ai_json) {
                document.getElementById('ai-response').innerText = JSON.stringify(parsed.ai_json, null, 2);
                responseSummary = parsed.ai_json.narrative || "Analysis Complete";
            } else {
                document.getElementById('ai-response').innerText = text;
                responseSummary = text.substring(0, 100);
            }

            addAILogEntry(new Date().toLocaleTimeString(), prompt, responseSummary);
        } catch (err) {
            console.error('AI fetch failed', err);
            document.getElementById('ai-response').innerText = 'AI error. Check console.';
        } finally {
            hideOverlay();
        }
    });

    // Initial Data Load
    updateClock();
    fetchSensorStatus();
    fetchSensorHistoryAndUpdateChart();
    fetchAILogs();

    // Start Loops
    setInterval(updateClock, 1000);
    setInterval(fetchSensorStatus, 1000);       
    setInterval(fetchSensorHistoryAndUpdateChart, 3000);
    setInterval(fetchAILogs, 10000);
});