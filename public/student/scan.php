<?php
/**
 * Student QR Scan Kiosk (public)
 *
 * Flow:
 *   1. Student shows their QR card -> webcam scans it
 *   2. QR decoded -> /api/scan.php verifies + logs attendance
 *   3. Result shown (success / already logged / no active session)
 *
 * Works standalone on a kiosk PC or on a phone. No login required.
 */
require_once __DIR__ . '/../../bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Scan | <?= config('name') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css?v=20261014">
    <link rel="stylesheet" href="/assets/css/scan.css">
    <style>
        #qr-reader { width: 100%; max-width: 420px; margin: 0 auto; }
        #qr-reader img, #qr-reader video { max-width: 100%; border-radius: var(--radius-sm); }
        #qr-reader__scan_region { border-radius: var(--radius-sm); overflow: hidden; }
    </style>
</head>
<body class="scan-page">
    <div class="scan-container">
        <div class="scan-header">
            <div class="scan-logo"><img src="/assets/images/logo.jpg" alt="<?= config('short_name') ?> logo"></div>
            <h1>QR Attendance Scan</h1>
            <p><?= config('school_name') ?> | S.Y. <?= config('academic_year') ?></p>
        </div>

        <div class="scan-steps">
            <div class="step active" id="step1-indicator">
                <span class="step-num">1</span>
                <span class="step-label">Scan QR</span>
            </div>
            <div class="step-connector"></div>
            <div class="step" id="step2-indicator">
                <span class="step-num">2</span>
                <span class="step-label">Done</span>
            </div>
        </div>

        <!-- Phase 1: QR Scanner -->
        <div id="phase-qr" class="scan-phase active">
            <div class="scan-card">
                <h2>Hold your QR card to the camera</h2>
                <p class="text-muted">Your attendance will be logged automatically.</p>
                <div id="qr-reader" class="qr-reader-container"></div>
                <div id="qr-result" class="qr-result" style="display:none;">
                    <div class="qr-spinner"></div>
                    <p>QR detected! Logging attendance...</p>
                </div>
                <div id="qr-error" class="alert alert-danger" style="display:none;margin-top:0.75rem;"></div>
            </div>
        </div>

        <!-- Phase 2: Result (auto-clears back to scanning — the record lives in the class session) -->
        <div id="phase-result" class="scan-phase">
            <div class="scan-card result-card">
                <div id="result-icon" class="result-icon result-success"></div>
                <h2 id="result-title">Attendance Logged!</h2>
                <p id="result-message"></p>
                <div id="result-details" class="result-details"></div>
                <p class="text-muted text-sm" id="result-autoreset">Scanning next student in <strong id="reset-count">3</strong>…</p>
                <button onclick="resetScan()" class="btn btn-primary">Scan Now</button>
                <a href="/" class="btn btn-outline">Home</a>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
    const STATE = { scanning: false };
    let html5QrCode = null;

    function showPhase(phaseId) {
        document.querySelectorAll('.scan-phase').forEach(p => p.classList.remove('active'));
        document.getElementById(phaseId).classList.add('active');
    }

    function setStep(step) {
        for (let i = 1; i <= 2; i++) {
            const el = document.getElementById('step' + i + '-indicator');
            el.classList.toggle('active', i <= step);
            el.classList.toggle('done', i < step);
        }
    }

    function startQrScanner() {
        showPhase('phase-qr');
        setStep(1);
        document.getElementById('qr-error').style.display = 'none';
        document.getElementById('qr-result').style.display = 'none';

        html5QrCode = new Html5QrcodeScanner(
            'qr-reader',
            {
                fps: 10,
                qrbox: { width: 250, height: 250 },
                rememberLastUsedCamera: true,
            },
            false
        );
        html5QrCode.render(onQrSuccess, onQrError);
    }

    function onQrSuccess(decodedText) {
        if (STATE.scanning) return;
        STATE.scanning = true;

        try { html5QrCode.clear(); } catch (e) {}

        document.getElementById('qr-result').style.display = 'block';

        const formData = new FormData();
        formData.append('student_id', decodedText.trim());

        fetch('/api/scan.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showResult('success', 'Attendance Logged!', data.message, {
                        'Student': data.student_name,
                        'Status': data.status.toUpperCase(),
                        'Time': data.time,
                        'Subject': data.subject,
                        'Logged to': data.subject + ' · ' + data.section
                    });
                } else if (data.already_logged) {
                    showResult('error', 'Already Logged', data.message, null);
                } else {
                    document.getElementById('qr-result').style.display = 'none';
                    const errEl = document.getElementById('qr-error');
                    errEl.textContent = data.message;
                    errEl.style.display = 'block';
                    STATE.scanning = false;
                    try { html5QrCode.render(onQrSuccess, onQrError); } catch (e) {}
                }
            })
            .catch(err => {
                document.getElementById('qr-result').style.display = 'none';
                document.getElementById('qr-error').textContent = 'Scan error: ' + err;
                document.getElementById('qr-error').style.display = 'block';
                STATE.scanning = false;
                try { html5QrCode.render(onQrSuccess, onQrError); } catch (e) {}
            });
    }

    function onQrError(err) {
        if (err && !String(err).includes('No MultiFormat Readers')) {
            console.warn('QR scan warning:', err);
        }
    }

    let resetTimer = null;

    function showResult(type, title, message, details) {
        showPhase('phase-result');
        setStep(2);

        const icon = document.getElementById('result-icon');
        icon.className = 'result-icon ' + (type === 'success' ? 'result-success' : 'result-error');
        icon.innerHTML = type === 'success'
            ? '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>'
            : '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
        document.getElementById('result-title').textContent = title;
        document.getElementById('result-message').textContent = message;

        const detailsEl = document.getElementById('result-details');
        if (details) {
            let html = '<table class="result-table">';
            for (const [key, val] of Object.entries(details)) {
                html += '<tr><td>' + key + '</td><td>' + val + '</td></tr>';
            }
            html += '</table>';
            detailsEl.innerHTML = html;
            detailsEl.style.display = 'block';
        } else {
            detailsEl.style.display = 'none';
        }

        // Kiosk flow: the result clears itself so the next student can tap.
        // The record itself lives in the teacher's class session, not here.
        if (resetTimer) clearInterval(resetTimer);
        let left = 3;
        document.getElementById('reset-count').textContent = left;
        resetTimer = setInterval(function () {
            left--;
            if (left <= 0) { clearInterval(resetTimer); resetScan(); return; }
            document.getElementById('reset-count').textContent = left;
        }, 1000);
    }

    function resetScan() {
        if (resetTimer) clearInterval(resetTimer);
        STATE.scanning = false;
        document.getElementById('qr-result').style.display = 'none';
        document.getElementById('qr-error').style.display = 'none';
        startQrScanner();
    }

    document.addEventListener('DOMContentLoaded', startQrScanner);
    </script>
</body>
</html>
