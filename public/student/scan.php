<?php
/**
 * QR Scanner Kiosk (public, separate from every dashboard)
 *
 * Open it on the classroom/kiosk PC (or a phone): /scan/ or /student/scan.php.
 *   1. The teacher starts the class from their dashboard (creates the active session)
 *   2. Students hold their QR card to this camera
 *   3. /api/scan.php finds the student's section -> the class running right now
 *      -> logs PRESENT / LATE into that teacher's session
 * No login needed here: the student's QR + the current time decide the class.
 */
require_once __DIR__ . '/../../bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#120E0C">
    <title>QR Scanner | <?= h(config('short_name')) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --k-bg: #120E0C;
            --k-panel: #1E1916;
            --k-line: rgba(255,255,255,0.10);
            --k-text: #F6F1EC;
            --k-muted: rgba(246,241,236,0.62);
            --k-brand: #C00000;
            --k-ok: #4B7E2E;
            --k-ok-soft: #E7F1E0;
            --k-late: #B87410;
            --k-late-soft: #FBF0DC;
            --k-bad: #C33030;
            --k-bad-soft: #FBE6E6;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            background: var(--k-bg); color: var(--k-text);
            font-family: 'Plus Jakarta Sans', 'Segoe UI', system-ui, -apple-system, sans-serif;
            display: flex; flex-direction: column; overflow: hidden;
        }

        /* ---------- Top bar ---------- */
        .k-top {
            display: flex; align-items: center; gap: 1rem;
            padding: 0.85rem 1.25rem; padding-top: max(0.85rem, env(safe-area-inset-top)); border-bottom: 1px solid var(--k-line);
            background: linear-gradient(90deg, rgba(192,0,0,0.22), transparent 60%);
        }
        .k-logo { width: 44px; height: 44px; border-radius: 12px; background: #fff; padding: 3px; flex: none; }
        .k-logo img { width: 100%; height: 100%; object-fit: contain; border-radius: 9px; }
        .k-title { min-width: 0; }
        .k-title h1 { font-size: 1.15rem; font-weight: 800; letter-spacing: 0.01em; }
        .k-title p { font-size: 0.82rem; color: var(--k-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .k-clock { margin-left: auto; text-align: right; font-variant-numeric: tabular-nums; }
        .k-clock strong { display: block; font-size: 1.6rem; font-weight: 800; line-height: 1.1; white-space: nowrap; }
        .k-clock span { font-size: 0.8rem; color: var(--k-muted); }
        .k-fs {
            flex: none; cursor: pointer; border: 1px solid var(--k-line); background: var(--k-panel);
            color: var(--k-text); border-radius: 999px; padding: 0.6rem 1rem; font: inherit; font-weight: 700; font-size: 0.85rem;
            display: inline-flex; align-items: center; gap: 0.45rem;
        }
        .k-fs:hover { border-color: rgba(255,255,255,0.3); }

        /* ---------- Camera ---------- */
        .k-main { flex: 1; min-height: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1rem; gap: 0.9rem; }
        .k-cam {
            width: min(94vw, calc((100vh - 13rem) * 4 / 3));
            border-radius: 20px; overflow: hidden; background: #000;
            border: 1px solid var(--k-line); box-shadow: 0 30px 80px rgba(0,0,0,0.45);
        }
        #qr-reader { width: 100% !important; border: 0 !important; color: var(--k-text); }
        #qr-reader video { width: 100% !important; display: block; max-height: calc(100vh - 13rem); object-fit: contain; }
        #qr-reader__dashboard { padding: 0.25rem 0.75rem !important; }
        /* While the camera runs, the library's control row is empty (Stop is hidden) - collapse it.
           It comes back for the first-time "Request Camera Permissions" / camera choice. */
        #qr-reader__dashboard:has(#html5-qrcode-button-camera-stop[style*="inline-block"]) { display: none; }
        #qr-reader__dashboard_section_csr button,
        #qr-reader button {
            padding: 0.7rem 1.2rem; border-radius: 999px; border: 0; background: var(--k-brand); color: #fff;
            font: inherit; font-weight: 700; cursor: pointer; margin: 0.25rem;
        }
        #qr-reader select { padding: 0.45rem; border-radius: 8px; font: inherit; }
        #qr-reader a, #qr-reader span { color: var(--k-text) !important; }
        #qr-reader img[alt="Info icon"] { display: none; }
        /* Kiosk: students must not be able to stop the camera or switch to file upload */
        #html5-qrcode-button-camera-stop, #html5-qrcode-anchor-scan-type-change { display: none !important; }
        .k-hint { font-size: clamp(1.1rem, 2.2vw, 1.6rem); font-weight: 700; text-align: center; }
        .k-hint small { display: block; font-size: 0.6em; font-weight: 500; color: var(--k-muted); margin-top: 0.2rem; }

        /* ---------- Result card ---------- */
        .k-result {
            position: fixed; inset: 0; z-index: 50; display: none;
            align-items: center; justify-content: center; padding: 1.5rem;
            background: rgba(10,8,7,0.55); backdrop-filter: blur(3px);
        }
        .k-result.show { display: flex; }
        .k-card {
            width: min(92vw, 620px); text-align: center; border-radius: 26px; padding: 2.4rem 2rem 2rem;
            background: #fff; color: #1B1613; border-top: 12px solid var(--k-ok);
            box-shadow: 0 30px 90px rgba(0,0,0,0.5); animation: pop 0.22s ease-out;
        }
        @keyframes pop { from { transform: scale(0.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .k-icon { width: 84px; height: 84px; margin: 0 auto 0.9rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .k-status { font-size: clamp(2.4rem, 6vw, 3.6rem); font-weight: 800; line-height: 1; letter-spacing: 0.02em; }
        .k-name { font-size: clamp(1.5rem, 3.6vw, 2.2rem); font-weight: 800; margin-top: 0.6rem; }
        .k-meta { font-size: 1.05rem; color: #6B615A; margin-top: 0.5rem; }
        .k-meta span { white-space: nowrap; }
        .k-meta span + span::before { content: "\00B7"; margin: 0 0.45rem; color: #A89E96; }
        .k-card.ok   { border-top-color: var(--k-ok); }   .k-card.ok   .k-icon { background: var(--k-ok-soft); color: var(--k-ok); }     .k-card.ok   .k-status { color: var(--k-ok); }
        .k-card.late { border-top-color: var(--k-late); } .k-card.late .k-icon { background: var(--k-late-soft); color: var(--k-late); } .k-card.late .k-status { color: var(--k-late); }
        .k-card.bad  { border-top-color: var(--k-bad); }  .k-card.bad  .k-icon { background: var(--k-bad-soft); color: var(--k-bad); }   .k-card.bad  .k-status { color: var(--k-bad); font-size: clamp(2rem, 5vw, 2.8rem); }
        .k-card.bad .k-name { font-size: clamp(1.15rem, 2.6vw, 1.5rem); font-weight: 600; }

        .k-busy { position: fixed; left: 50%; bottom: 1.25rem; transform: translateX(-50%); z-index: 40; display: none;
                  background: var(--k-panel); border: 1px solid var(--k-line); border-radius: 999px; padding: 0.6rem 1.1rem; font-weight: 700; }
        .k-busy.show { display: block; }

        /* ---------- Phones / small tablets ---------- */
        @media (max-width: 640px) {
            .k-top { padding: 0.6rem 0.85rem; padding-top: max(0.6rem, env(safe-area-inset-top)); gap: 0.6rem; }
            .k-logo { width: 36px; height: 36px; border-radius: 10px; }
            .k-title h1 { font-size: 1rem; white-space: nowrap; }
            .k-title h1 .k-long, .k-title p, .k-clock span, .k-fs span { display: none; }
            .k-clock strong { font-size: 1.15rem; }
            .k-fs { padding: 0.55rem 0.7rem; }
            .k-main { justify-content: flex-start; padding: 0.85rem 0.85rem max(0.85rem, env(safe-area-inset-bottom)); gap: 0.75rem; }
            .k-cam { width: 100%; border-radius: 16px; }
            #qr-reader video { max-height: calc(100dvh - 12rem); }
            .k-hint { font-size: 1.05rem; }
            .k-hint small { font-size: 0.8rem; }
            .k-card { padding: 1.8rem 1.25rem 1.5rem; border-radius: 22px; border-top-width: 10px; }
            .k-icon { width: 68px; height: 68px; }
            .k-icon svg { width: 36px; height: 36px; }
            .k-meta { font-size: 0.95rem; }
        }
        /* Phone held sideways: camera beside nothing - just use the height */
        @media (max-height: 500px) and (orientation: landscape) {
            .k-top { padding-top: 0.4rem; padding-bottom: 0.4rem; }
            .k-hint small { display: none; }
            .k-cam { width: min(94vw, calc((100dvh - 7.5rem) * 4 / 3)); }
            #qr-reader video { max-height: calc(100dvh - 7.5rem); }
        }
    </style>
</head>
<body>
    <header class="k-top">
        <div class="k-logo"><img src="/assets/images/logo.jpg" alt="<?= h(config('short_name')) ?> logo"></div>
        <div class="k-title">
            <h1>QR<span class="k-long"> Attendance</span> Scanner</h1>
            <p><?= h(config('school_name')) ?> &middot; S.Y. <?= h(config('academic_year')) ?></p>
        </div>
        <div class="k-clock"><strong id="k-time">--:--</strong><span id="k-date"></span></div>
        <button type="button" class="k-fs" id="k-fs" onclick="toggleFullscreen()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M3 8V5a2 2 0 0 1 2-2h3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3"/></svg>
            <span id="k-fs-label">Full screen</span>
        </button>
    </header>

    <main class="k-main">
        <div class="k-cam"><div id="qr-reader"></div></div>
        <p class="k-hint">Hold your QR card in front of the camera
            <small>Your attendance is logged to the class that is running right now.</small></p>
    </main>

    <div class="k-busy" id="k-busy">Checking&hellip;</div>

    <div class="k-result" id="k-result" role="alert" aria-live="assertive">
        <div class="k-card" id="k-card">
            <div class="k-icon" id="k-icon"></div>
            <div class="k-status" id="k-status"></div>
            <div class="k-name" id="k-name"></div>
            <div class="k-meta" id="k-meta"></div>
        </div>
    </div>

    <script src="/assets/vendor/html5-qrcode-2.3.8.min.js"></script>
    <script>
    const ICON_OK  = '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>';
    const ICON_BAD = '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    const ICON_WARN = '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><line x1="12" y1="7" x2="12" y2="13"/><circle cx="12" cy="17.5" r="0.6" fill="currentColor"/></svg>';

    let scanner = null;
    let busy = false;            // a scan is being checked / its result is on screen
    let lastCode = '', lastAt = 0; // ignore the same card held in front of the camera
    let hideTimer = null;

    function qrbox(w, h) {
        const s = Math.max(200, Math.floor(Math.min(w, h) * 0.6));
        return { width: s, height: s };
    }

    function startScanner() {
        scanner = new Html5QrcodeScanner('qr-reader', { fps: 10, qrbox, rememberLastUsedCamera: true }, false);
        scanner.render(onScan, () => {});
    }

    async function restartScanner() {
        // The scanner measures its size when it starts; clear() is async, so wait for it.
        if (!scanner) return startScanner();
        try { await scanner.clear(); } catch (e) {}
        startScanner();
    }

    function onScan(text) {
        const code = String(text || '').trim();
        const now = Date.now();
        if (busy || !code) return;
        if (code === lastCode && now - lastAt < 5000) return;
        lastCode = code; lastAt = now;
        busy = true;
        document.getElementById('k-busy').classList.add('show');

        const fd = new FormData();
        fd.append('student_id', code);
        fetch('/api/scan.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    const late = String(d.status).toLowerCase() === 'late';
                    show(late ? 'late' : 'ok', ICON_OK, String(d.status || 'present').toUpperCase(),
                         d.student_name || '', [d.subject, d.section, d.time].filter(Boolean).join(' · '), 2500);
                } else if (d.already_logged) {
                    show('late', ICON_WARN, 'Already logged', d.message || '', '', 2500);
                } else {
                    show('bad', ICON_BAD, 'Not recorded', d.message || 'Please try again.', '', 3500);
                }
            })
            .catch(() => show('bad', ICON_BAD, 'Not recorded', 'Could not reach the system. Please try again.', '', 3500));
    }

    function show(kind, icon, status, name, meta, ms) {
        document.getElementById('k-busy').classList.remove('show');
        document.getElementById('k-card').className = 'k-card ' + kind;
        document.getElementById('k-icon').innerHTML = icon;
        document.getElementById('k-status').textContent = status;
        document.getElementById('k-name').textContent = name;
        const metaEl = document.getElementById('k-meta');
        metaEl.textContent = '';
        (Array.isArray(meta) ? meta : [meta]).filter(Boolean).forEach(m => {
            const sp = document.createElement('span'); sp.textContent = m; metaEl.appendChild(sp);
        });
        document.getElementById('k-result').classList.add('show');
        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => {
            document.getElementById('k-result').classList.remove('show');
            busy = false; // camera never stopped - ready for the next student
        }, ms);
    }

    function tick() {
        const d = new Date();
        document.getElementById('k-time').textContent = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        document.getElementById('k-date').textContent = d.toLocaleDateString([], { weekday: 'long', month: 'short', day: 'numeric' });
    }

    function toggleFullscreen() {
        if (document.fullscreenElement) { document.exitFullscreen().catch(() => {}); }
        else if (document.documentElement.requestFullscreen) { document.documentElement.requestFullscreen().catch(() => {}); }
    }
    document.addEventListener('fullscreenchange', () => {
        document.getElementById('k-fs-label').textContent = document.fullscreenElement ? 'Exit full screen' : 'Full screen';
        if (!busy) setTimeout(restartScanner, 200); // camera area changed size
    });

    document.addEventListener('DOMContentLoaded', () => {
        tick(); setInterval(tick, 1000);
        if (typeof Html5QrcodeScanner === 'undefined') {
            show('bad', ICON_BAD, 'Scanner not loaded', 'Reload the page. If it keeps happening, tell your teacher.', '', 600000);
            return;
        }
        startScanner();
    });
    </script>
    <script src="<?= asset("/assets/js/zoom-lock.js") ?>" defer></script>
</body>
</html>
