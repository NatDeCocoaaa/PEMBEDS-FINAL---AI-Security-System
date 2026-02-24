const CALLAI_PATH = '/fProject_PEMBEDS%202/BackEnd/CallAI.php'; 
const SENSOR_POLL_PATH = '/fProject_PEMBEDS%202/BackEnd/sensor_fetch.php?device_id=1';
const SENSOR_HISTORY_PATH = '/fProject_PEMBEDS%202/BackEnd/sensor_history.php?device_id=1&limit=50';
const AI_LOGS_PATH = '/fProject_PEMBEDS%202/BackEnd/fetch_ai_logs.php?limit=50';

async function fetchAILogs() {
    try {
        const res = await fetch(AI_LOGS_PATH);
        if (!res.ok) {
            console.warn('fetchAILogs failed', res.status);
            return;
        }
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
let sensorChart = null;

async function fetchSensorStatus() {
    try {
        const res = await fetch(SENSOR_POLL_PATH);
        if (!res.ok) {
            console.warn('Sensor fetch failed', res.status);
            return;
        }
        const j = await res.json();
        if (!j.ok || !j.status) return;
        const s = j.status;
        document.getElementById('dist-val').innerText = (s.distance === null) ? '-- cm' : (s.distance + ' cm');
        document.getElementById('sound-val').innerText = (s.sound_db === null) ? '-- dB' : (s.sound_db + ' dB');
        document.getElementById('pir-status').innerText = s.pir_status || 'STABLE';
        document.getElementById('lock-text').innerText = s.lock_state || 'UNLOCKED';
        document.getElementById('lock-icon').innerText = (s.lock_state === 'HARD-LOCK') ? '🔒' : '🔓';
    } catch (err) {
        console.error('fetchSensorStatus error', err);
    }
}

async function fetchSensorHistoryAndUpdateChart() {
    try {
        const res = await fetch(SENSOR_HISTORY_PATH);
        if (!res.ok) {
            console.warn('history fetch failed', res.status);
            return;
        }
        const j = await res.json();
        if (!j.ok || !j.data) return;

        const rows = j.data; // chronological oldest > newest
        const labels = rows.map(r => {
            const d = new Date(r.created_at);
            return d.toLocaleTimeString();
        });

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
                            tension: 0.25,
                            borderColor: 'rgba(56,189,248,1)',
                            backgroundColor: 'rgba(56,189,248,0.1)',
                            spanGaps: true
                        },
                        {
                            label: 'Sound (raw)',
                            data: soundData,
                            yAxisID: 'y2',
                            borderWidth: 2,
                            tension: 0.25,
                            borderColor: 'rgba(99,102,241,1)',
                            backgroundColor: 'rgba(99,102,241,0.08)',
                            spanGaps: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    interaction: { mode: 'index', intersect: false },
                    stacked: false,
                    scales: {
                        x: {
                            display: true,
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            beginAtZero: true,
                            title: { display: true, text: 'Distance (cm)' }
                        },
                        y2: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            beginAtZero: true,
                            grid: { drawOnChartArea: false },
                            title: { display: true, text: 'Sound (raw)' }
                        }
                    }
                }
            });
        } else {
            sensorChart.data.labels = labels;
            sensorChart.data.datasets[0].data = distanceData;
            sensorChart.data.datasets[1].data = soundData;
            sensorChart.update();
        }
    } catch (err) {
        console.error('fetchSensorHistoryAndUpdateChart err', err);
    }
}

function updateClock() {
    const now = new Date();
    document.getElementById('clock').innerText = now.toLocaleTimeString();
}
setInterval(updateClock, 1000);

function showOverlay() { document.getElementById('ai-overlay').classList.remove('hidden'); }
function hideOverlay() { document.getElementById('ai-overlay').classList.add('hidden'); }

function addLogEntry(time, decision, method) {
    const table = document.getElementById('log-body');
    const row = table.insertRow(0);
    const hour = parseInt(time.split(':')[0]);
    if (hour >= 22 || hour < 6) row.style.backgroundColor = "#330000";
    row.innerHTML = `<td class="p-3">${time}</td><td class="p-3 uppercase font-bold">${escapeHtml(decision)}</td><td class="p-3 text-gray-500">${escapeHtml(method)}</td>`;
}

function addAILogEntry(time, prompt, response) {
    const table = document.getElementById('ai-log-body');
    const row = table.insertRow(0);
    row.innerHTML = `<td class="p-2 align-top">${time}</td>
                     <td class="p-2 align-top font-mono text-xs" style="max-width:240px;word-wrap:break-word;">${escapeHtml(prompt)}</td>
                     <td class="p-2 align-top font-mono text-xs" style="max-width:360px;word-wrap:break-word;">${escapeHtml(response)}</td>`;
}

async function remoteUnlock() {
    const pin = document.getElementById('web-pin').value;
    if(!pin) return;

    try {
        const res = await fetch('/fProject_PEMBEDS%202/BackEnd/send_command.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ pin })
        });
        const data = await res.json();
        
        alert(data.message);
        document.getElementById('web-pin').value = '';
    } catch (err) {
        alert("Connection Error");
    }
}

function escapeHtml(unsafe) {
    if (!unsafe && unsafe !== 0) return '';
    return String(unsafe)
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

function showTab(tab) {
    document.getElementById('tab-content-logs').classList.toggle('hidden', tab !== 'logs');
    document.getElementById('tab-content-ai').classList.toggle('hidden', tab !== 'ai');
    document.getElementById('tab-logs').classList.toggle('border-cyan-500', tab === 'logs');
    document.getElementById('tab-ai').classList.toggle('border-cyan-500', tab === 'ai');
    document.getElementById('tab-logs').classList.toggle('text-cyan-300', tab === 'logs');
    document.getElementById('tab-ai').classList.toggle('text-cyan-300', tab === 'ai');
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('tab-logs').addEventListener('click', () => showTab('logs'));
    document.getElementById('tab-ai').addEventListener('click', () => showTab('ai'));

    showTab('logs');

    const form = document.getElementById('ai-form');
    form.addEventListener('submit', async (e) => {
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

            if (!res.ok) {
                console.error('Server error', res.status, text);
                addAILogEntry(new Date().toLocaleTimeString(), prompt, `SERVER ERROR ${res.status}`);
                document.getElementById('ai-response').innerText = `Server error ${res.status}: see console`;
                return;
            }

            let parsed = null;
            try { parsed = JSON.parse(text); } catch (err) { parsed = null; }

            let displayText = text;
            let responseSummary = '';
            if (parsed && parsed.ai_json) {
                const aiJson = parsed.ai_json;
                displayText = JSON.stringify(aiJson, null, 2);
                responseSummary = aiJson.summary || JSON.stringify(aiJson);
            } else if (parsed && parsed.ai_text) {
                displayText = parsed.ai_text;
                responseSummary = parsed.ai_text;
            } else {
                displayText = text;
                responseSummary = text;
            }

            document.getElementById('ai-response').innerText = displayText;
            addAILogEntry(new Date().toLocaleTimeString(), prompt, responseSummary);

        } catch (err) {
            console.error('Fetch failed', err);
            addAILogEntry(new Date().toLocaleTimeString(), prompt, 'Fetch failed (see console)');
            document.getElementById('ai-response').innerText = 'AI fetch error. See console.';
        } finally {
            hideOverlay();
        }
    });

    fetchSensorStatus();
    fetchSensorHistoryAndUpdateChart();
    fetchAILogs();
    setInterval(fetchSensorStatus, 1000);       
    setInterval(fetchSensorHistoryAndUpdateChart, 2000);
    setInterval(fetchAILogs, 5000);

});