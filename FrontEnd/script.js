const CALLAI_PATH = '/fProject_PEMBEDS%202/BackEnd/CallAI.php'; 

const SENSOR_POLL_PATH = '/fProject_PEMBEDS%202/BackEnd/sensor_fetch.php?device_id=1';

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

setInterval(fetchSensorStatus, 1000);
fetchSensorStatus();

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

    // initial mock entries remove when ready
    addLogEntry("23:15:04", "Intrusion Denied", "Keypad Fail");
    addAILogEntry("23:16:10", "Analyze last 10 rows for anomalies", "No anomalies (sample)");
});