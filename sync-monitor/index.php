<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
deny_if_external();
?><!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NAS Sync Monitor</title>
  <style>
    :root { --bg:#1a1d23; --card:#252a33; --text:#e8eaed; --muted:#9aa0a6; --accent:#6ba3ff; --homes:#c9a227; --ok:#5bd18a; --warn:#f0b429; --err:#f07178; }
    * { box-sizing: border-box; }
    body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 1rem 1.5rem 2rem; }
    h1 { font-size: 1.35rem; margin: 0 0 .25rem; }
    h3 { font-size: .85rem; color: var(--muted); margin: 1.25rem 0 .5rem; text-transform: uppercase; letter-spacing: .04em; }
    .sub { color: var(--muted); font-size: .9rem; margin-bottom: 1.25rem; }
    .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
    .card { background: var(--card); border-radius: 10px; padding: 1rem 1.1rem; }
    .card h2 { font-size: .95rem; margin: 0 0 .75rem; color: var(--accent); }
    .card.homes h2 { color: var(--homes); }
    .badge { display: inline-block; padding: .15rem .5rem; border-radius: 4px; font-size: .8rem; }
    .on { background: #1e3d2f; color: var(--ok); }
    .off { background: #3d2a1e; color: var(--warn); }
    .pending { background: #3d351e; color: var(--warn); }
    pre { background: #16191f; padding: .75rem; border-radius: 6px; font-size: .72rem; max-height: 220px; overflow: auto; white-space: pre-wrap; word-break: break-all; }
    label { display: block; font-size: .8rem; color: var(--muted); margin-top: .6rem; }
    input, textarea { width: 100%; background: #16191f; border: 1px solid #3a4048; color: var(--text); border-radius: 6px; padding: .45rem .55rem; font: inherit; }
    textarea { min-height: 100px; font-family: ui-monospace, monospace; font-size: .78rem; }
    .btns { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
    button { background: var(--accent); color: #111; border: 0; border-radius: 6px; padding: .45rem .85rem; cursor: pointer; font-weight: 600; }
    button.homes { background: var(--homes); }
    button.secondary { background: #3a4048; color: var(--text); }
    button.danger { background: #8b3a3a; color: #fff; }
    table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    td, th { text-align: left; padding: .35rem .25rem; border-bottom: 1px solid #333; }
    #msg { margin: .75rem 0; min-height: 1.2rem; color: var(--ok); font-size: .9rem; }
    .disk { font-size: .82rem; color: var(--muted); margin-top: .35rem; }
    .jobs-grid { display: grid; gap: .85rem; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); margin-bottom: 1.5rem; }
    .job-card { background: var(--card); border-radius: 10px; padding: .9rem 1rem; border-left: 4px solid #3a4048; }
    .job-card.st-ok { border-left-color: var(--ok); }
    .job-card.st-running { border-left-color: var(--accent); }
    .job-card.st-partial, .job-card.st-warn { border-left-color: var(--warn); }
    .job-card.st-waiting { border-left-color: #8a8f98; }
    .job-card.st-error { border-left-color: var(--err); }
    .job-card h2 { font-size: .92rem; margin: 0 0 .55rem; display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
    .job-card h2 span.title { color: var(--text); font-weight: 600; }
    .st-badge { font-size: .72rem; font-weight: 700; padding: .2rem .5rem; border-radius: 4px; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
    .st-badge.ok { background: #1e3d2f; color: var(--ok); }
    .st-badge.running { background: #1e2d3d; color: var(--accent); }
    .st-badge.partial, .st-badge.warn { background: #3d351e; color: var(--warn); }
    .job-card.st-warn .st-badge.warn { background: #3d2a1e; }
    .st-badge.waiting { background: #2a2d33; color: #b0b5bc; }
    .st-badge.error { background: #3d1e1e; color: var(--err); }
    .st-badge.unknown { background: #2a2d33; color: var(--muted); }
    .job-row { font-size: .8rem; margin: .3rem 0; line-height: 1.4; }
    .job-row strong { color: var(--muted); font-weight: 600; }
    .job-errors { margin: .45rem 0 0; padding: .45rem .55rem; background: #16191f; border-radius: 6px; font-size: .72rem; color: #f0b0b0; }
    .job-hints { margin: .35rem 0 0; padding: 0; list-style: none; font-size: .72rem; color: var(--warn); }
    .job-hints li::before { content: '→ '; }
  </style>
</head>
<body>
  <h1>NAS Sync Monitor</h1>
  <p class="sub">Belső panel · <?= htmlspecialchars(BIND_HOST . ':' . BIND_PORT) ?> · csak LAN/VPN · <a href="docs.php" style="color:var(--accent)">Dokumentáció</a></p>
  <div id="msg"></div>

  <h3>Áttekintés — jobok</h3>
  <div id="jobs-dashboard" class="jobs-grid">Betöltés…</div>

  <div class="grid">
    <div class="card">
      <h2>Globális vezérlés</h2>
      <div id="status-proc">Betöltés…</div>
      <div class="btns">
        <button type="button" onclick="ctl('start')">Start mind</button>
        <button type="button" class="secondary" onclick="ctl('restart')">Restart</button>
        <button type="button" class="danger" onclick="ctl('stop')">Stop mind</button>
        <button type="button" class="secondary" onclick="refresh()">Frissítés</button>
        <button type="button" class="secondary" onclick="loadSizes()">Méretek (lassú)</button>
      </div>
    </div>
  </div>

  <h3 id="video-section-title">DSM2 — videó bidir (naszareti)</h3>
  <div class="grid">
    <div class="card">
      <h2>Videó állapot</h2>
      <div id="video-proc">—</div>
      <div class="disk" id="video-disk"></div>
      <div class="btns">
        <button type="button" id="video-sync-btn" onclick="videoSyncNow()">Videó bidir most (DSM2)</button>
      </div>
      <p id="video-bidir-note" class="sub" style="margin:.75rem 0 0;font-size:.78rem;display:none">
        Nasznagy videó push kikapcsolva — vezérlés a DSM2-n (poll + boot task). A beállítások alul csak legacy / mappalista.
      </p>
    </div>
    <div class="card">
      <h2>Videó mappák</h2>
      <div id="video-sizes">—</div>
    </div>
    <div class="card">
      <h2 id="video-log-title">video_bidir.log (DSM2)</h2>
      <pre id="video-log">…</pre>
    </div>
  </div>

  <div class="grid" style="margin-top:.5rem">
    <div class="card">
      <h2>Videó beállítások</h2>
      <form id="video-cfg-form">
        <label>REMOTE_HOST</label>
        <input name="REMOTE_HOST" id="v_REMOTE_HOST">
        <label>REMOTE_PORT</label>
        <input name="REMOTE_PORT" id="v_REMOTE_PORT" type="number">
        <label>RSYNC_BWLIMIT (KB/s)</label>
        <input name="RSYNC_BWLIMIT" id="v_RSYNC_BWLIMIT" type="number">
        <label>POLL_INTERVAL_SEC</label>
        <input name="POLL_INTERVAL_SEC" id="v_POLL_INTERVAL_SEC" type="number">
        <label>sync_folders.conf</label>
        <textarea name="folders_conf" id="folders_conf"></textarea>
        <div class="btns"><button type="submit">Videó mentés</button></div>
      </form>
    </div>
  </div>

  <h3>DSM3 — homes (naszika · 192.168.9.29)</h3>
  <div class="grid">
    <div class="card homes">
      <h2>Homes állapot</h2>
      <div id="homes-proc">—</div>
      <div class="disk" id="homes-disk"></div>
      <div class="btns">
        <button type="button" class="homes" onclick="ctl('sync_homes_now')">Homes sync most</button>
      </div>
    </div>
    <div class="card homes">
      <h2>User Drive mappák</h2>
      <div id="homes-sizes">—</div>
    </div>
    <div class="card homes">
      <h2>homes_sync.log</h2>
      <pre id="homes-log">…</pre>
    </div>
  </div>

  <div class="grid" style="margin-top:.5rem">
    <div class="card homes">
      <h2>Homes beállítások</h2>
      <form id="homes-cfg-form">
        <label>REMOTE_HOST (naszika IP)</label>
        <input name="REMOTE_HOST" id="h_REMOTE_HOST">
        <label>REMOTE_PORT</label>
        <input name="REMOTE_PORT" id="h_REMOTE_PORT" type="number">
        <label>RSYNC_BWLIMIT (KB/s)</label>
        <input name="RSYNC_BWLIMIT" id="h_RSYNC_BWLIMIT" type="number">
        <label>POLL_INTERVAL_SEC (változás-ellenőrzés)</label>
        <input name="POLL_INTERVAL_SEC" id="h_POLL_INTERVAL_SEC" type="number">
        <label>SYNC_HOUR_START (éjszakai ablak)</label>
        <input name="SYNC_HOUR_START" id="h_SYNC_HOUR_START" type="number" min="0" max="23">
        <label>SYNC_HOUR_END</label>
        <input name="SYNC_HOUR_END" id="h_SYNC_HOUR_END" type="number" min="0" max="24">
        <label>sync_homes_folders.conf</label>
        <textarea name="homes_folders_conf" id="homes_folders_conf"></textarea>
        <div class="btns"><button type="submit" class="homes">Homes mentés</button></div>
      </form>
    </div>
    <div class="card">
      <h2>Webcam log</h2>
      <pre id="webcam-log">…</pre>
    </div>
  </div>

<script>
const msg = (t, ok=true) => { const el = document.getElementById('msg'); el.style.color = ok ? 'var(--ok)' : 'var(--warn)'; el.textContent = t; };

function renderJobs(jobs) {
  if (!jobs || !jobs.length) {
    document.getElementById('jobs-dashboard').innerHTML = '<div class="job-card">Nincs job adat</div>';
    return;
  }
  document.getElementById('jobs-dashboard').innerHTML = jobs.map(j => {
    const st = j.status || 'unknown';
    let html = `<div class="job-card st-${st}"><h2><span class="title">${j.name}</span><span class="st-badge ${st}">${j.status_label || st}</span></h2>`;
    html += `<div class="job-row"><strong>Utolsó futás:</strong> ${j.last_start || '—'}`;
    if (j.last_end) html += ` → ${j.last_end}`;
    html += `</div>`;
    html += `<div class="job-row"><strong>Időtartam:</strong> ${j.duration_human || '—'}</div>`;
    html += `<div class="job-row"><strong>Következő:</strong> ${j.next_run || '—'}</div>`;
    if (j.errors && j.errors.length) {
      html += `<div class="job-errors">${j.errors.map(e => '⚠ ' + e).join('<br>')}</div>`;
    }
    if (j.hints && j.hints.length) {
      html += '<ul class="job-hints">' + j.hints.map(h => `<li>${h}</li>`).join('') + '</ul>';
    }
    return html + '</div>';
  }).join('');
}

function folderTable(folders, remoteLabel) {
  let tbl = `<table><tr><th>User</th><th>Helyi</th><th>${remoteLabel}</th></tr>`;
  folders.forEach(f => {
    const label = f.label || f.src.split('/').slice(-2).join('/');
    tbl += `<tr><td>${label}</td><td>${f.local}</td><td>${f.remote}</td></tr>`;
  });
  return tbl + '</table>';
}

let refreshBusy = false;

let videoBidirMode = true;

function renderVideoProc(p, v) {
  const bidir = !!v.bidir;
  videoBidirMode = bidir;
  document.getElementById('video-bidir-note').style.display = bidir ? 'block' : 'none';
  document.getElementById('video-sync-btn').textContent = bidir ? 'Videó bidir most (DSM2)' : 'Videó sync most';
  document.getElementById('video-log-title').textContent = bidir ? 'video_bidir.log (DSM2)' : 'video_sync.log';
  document.getElementById('video-section-title').textContent = bidir
    ? 'DSM2 — videó bidir (naszareti · Ederics↔BP)'
    : 'DSM2 — videó (naszareti)';

  let html = '';
  if (bidir) {
    const sshOk = p.video_bidir_ok !== false;
    html += `<div>Bidir figyelő (DSM2): <span class="badge ${p.video_bidir_trigger?'on':'off'}">${p.video_bidir_trigger?'fut':'áll'}</span>`;
    if (!sshOk) html += ' <span class="badge off">SSH hiba</span>';
    html += '</div>';
    html += `<div style="margin-top:.35rem;font-size:.78rem;color:var(--muted)">Nasznagy videó trigger: <span class="badge off">kikapcsolva</span></div>`;
  } else {
    html += `<div>Videó trigger: <span class="badge ${p.video_trigger?'on':'off'}">${p.video_trigger?'fut':'áll'}</span></div>`;
  }
  if (p.video_rsync.length) {
    html += '<div style="margin-top:.5rem"><span class="badge on">rsync fut</span><pre style="margin-top:.5rem">'+p.video_rsync.join('\n')+'</pre></div>';
  } else {
    html += '<div style="margin-top:.5rem"><span class="badge off">nincs aktív rsync</span></div>';
  }
  document.getElementById('video-proc').innerHTML = html;
}

function videoSyncNow() {
  ctl(videoBidirMode ? 'sync_video_bidir_now' : 'sync_now');
}

async function refresh() {
  if (refreshBusy) return;
  refreshBusy = true;
  try {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), 45000);
  const r = await fetch('api.php?action=status', { signal: ctrl.signal });
  clearTimeout(t);
  const d = await r.json();
  renderJobs(d.jobs);
  const p = d.processes;
  const v = d.video;
  const videoLine = v.bidir
    ? `Bidir figyelő (DSM2): <span class="badge ${p.video_bidir_trigger?'on':'off'}">${p.video_bidir_trigger?'fut':'áll'}</span>`
    : `Videó trigger: <span class="badge ${p.video_trigger?'on':'off'}">${p.video_trigger?'fut':'áll'}</span>`;
  document.getElementById('status-proc').innerHTML = `
    <div>${videoLine}</div>
    <div style="margin-top:.4rem">Homes trigger: <span class="badge ${p.homes_trigger?'on':'off'}">${p.homes_trigger?'fut':'áll'}</span>
      ${p.homes_pending ? ' <span class="badge pending">pending változás</span>' : ''}</div>
    <div style="margin-top:.4rem;font-size:.8rem;color:var(--muted)">Frissítve: ${d.time}</div>`;

  renderVideoProc(p, v);
  document.getElementById('video-disk').textContent = 'DSM2 tár: ' + (v.remote_disk || '—');
  document.getElementById('video-sizes').innerHTML = folderTable(v.folders, 'DSM2');
  document.getElementById('video-log').textContent = v.log;

  const h = d.homes;
  if (p.homes_rsync.length) {
    document.getElementById('homes-proc').innerHTML =
      '<span class="badge on">rsync fut</span><pre style="margin-top:.5rem">'+p.homes_rsync.join('\n')+'</pre>';
  } else if (p.homes_sync) {
    document.getElementById('homes-proc').innerHTML =
      '<span class="badge on">homes sync fut</span> <span class="muted">(fájllista / első pár…)</span>';
  } else {
    document.getElementById('homes-proc').innerHTML = '<span class="badge off">nincs aktív sync</span>';
  }
  document.getElementById('homes-disk').textContent = 'Naszika tár: ' + (h.remote_disk || '—');
  document.getElementById('homes-sizes').innerHTML = folderTable(h.folders, 'naszika');
  document.getElementById('homes-log').textContent = h.log;
  document.getElementById('webcam-log').textContent = d.webcam_log;

  ['REMOTE_HOST','REMOTE_PORT','RSYNC_BWLIMIT','POLL_INTERVAL_SEC'].forEach(k => {
    const el = document.getElementById('v_' + k);
    if (el && v.env[k] !== undefined) el.value = v.env[k];
  });
  document.getElementById('folders_conf').value = v.folders_conf;

  ['REMOTE_HOST','REMOTE_PORT','RSYNC_BWLIMIT','POLL_INTERVAL_SEC','SYNC_HOUR_START','SYNC_HOUR_END'].forEach(k => {
    const el = document.getElementById('h_' + k);
    if (el && h.env[k] !== undefined) el.value = h.env[k];
  });
  document.getElementById('homes_folders_conf').value = h.folders_conf;
  } catch (e) {
    msg('Frissítés timeout — a panel újrapróbálja', false);
  } finally {
    refreshBusy = false;
  }
}

async function ctl(cmd) {
  const labels = {
    sync_now: 'videó',
    sync_video_bidir_now: 'videó bidir (DSM2)',
    sync_homes_now: 'homes (naszika)'
  };
  if ((cmd === 'sync_now' || cmd === 'sync_video_bidir_now' || cmd === 'sync_homes_now') && !confirm(`Azonnali ${labels[cmd] || cmd} sync indul. OK?`)) return;
  const fd = new FormData(); fd.append('action','control'); fd.append('cmd', cmd);
  const r = await fetch('api.php', { method:'POST', body: fd });
  const d = await r.json();
  msg(d.ok ? (d.output || 'Kész') : (d.error || 'Hiba'), d.ok);
  setTimeout(refresh, 1500);
}

document.getElementById('video-cfg-form').onsubmit = async (ev) => {
  ev.preventDefault();
  const fd = new FormData(ev.target);
  fd.append('action', 'save');
  ['REMOTE_HOST','REMOTE_PORT','RSYNC_BWLIMIT','POLL_INTERVAL_SEC'].forEach(k => fd.append('env['+k+']', fd.get(k)));
  const r = await fetch('api.php', { method:'POST', body: fd });
  const d = await r.json();
  msg(d.ok ? d.message : d.error, d.ok);
  refresh();
};

document.getElementById('homes-cfg-form').onsubmit = async (ev) => {
  ev.preventDefault();
  const fd = new FormData(ev.target);
  fd.append('action', 'save');
  ['REMOTE_HOST','REMOTE_PORT','RSYNC_BWLIMIT','POLL_INTERVAL_SEC','SYNC_HOUR_START','SYNC_HOUR_END'].forEach(k => fd.append('homes_env['+k+']', fd.get(k)));
  fd.append('homes_folders_conf', fd.get('homes_folders_conf'));
  const r = await fetch('api.php', { method:'POST', body: fd });
  const d = await r.json();
  msg(d.ok ? d.message : d.error, d.ok);
  refresh();
};

async function loadSizes() {
  msg('Méretek betöltése… (több percig tarthat)', true);
  try {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 120000);
    const r = await fetch('api.php?action=status&sizes=1&disk=1', { signal: ctrl.signal });
    clearTimeout(t);
    const d = await r.json();
    document.getElementById('video-sizes').innerHTML = folderTable(d.video.folders, 'DSM2');
    document.getElementById('homes-sizes').innerHTML = folderTable(d.homes.folders, 'naszika');
    document.getElementById('video-disk').textContent = 'DSM2 tár: ' + (d.video.remote_disk || '—');
    document.getElementById('homes-disk').textContent = 'Naszika tár: ' + (d.homes.remote_disk || '—');
    msg(d.sizes_included ? 'Méretek frissítve' : 'Kész', true);
  } catch (e) {
    msg('Méretek betöltése timeout — próbáld újra később', false);
  }
}

refresh();
setInterval(refresh, 60000);
</script>
</body>
</html>
