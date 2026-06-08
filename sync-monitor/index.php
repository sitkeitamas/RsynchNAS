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
    :root { --bg:#1a1d23; --card:#252a33; --text:#e8eaed; --muted:#9aa0a6; --accent:#6ba3ff; --ok:#5bd18a; --warn:#f0b429; }
    * { box-sizing: border-box; }
    body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 1rem 1.5rem 2rem; }
    h1 { font-size: 1.35rem; margin: 0 0 .25rem; }
    .sub { color: var(--muted); font-size: .9rem; margin-bottom: 1.25rem; }
    .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
    .card { background: var(--card); border-radius: 10px; padding: 1rem 1.1rem; }
    .card h2 { font-size: .95rem; margin: 0 0 .75rem; color: var(--accent); }
    .badge { display: inline-block; padding: .15rem .5rem; border-radius: 4px; font-size: .8rem; }
    .on { background: #1e3d2f; color: var(--ok); }
    .off { background: #3d2a1e; color: var(--warn); }
    pre { background: #16191f; padding: .75rem; border-radius: 6px; font-size: .72rem; max-height: 220px; overflow: auto; white-space: pre-wrap; word-break: break-all; }
    label { display: block; font-size: .8rem; color: var(--muted); margin-top: .6rem; }
    input, textarea { width: 100%; background: #16191f; border: 1px solid #3a4048; color: var(--text); border-radius: 6px; padding: .45rem .55rem; font: inherit; }
    textarea { min-height: 120px; font-family: ui-monospace, monospace; font-size: .78rem; }
    .btns { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
    button { background: var(--accent); color: #111; border: 0; border-radius: 6px; padding: .45rem .85rem; cursor: pointer; font-weight: 600; }
    button.secondary { background: #3a4048; color: var(--text); }
    button.danger { background: #8b3a3a; color: #fff; }
    table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    td, th { text-align: left; padding: .35rem .25rem; border-bottom: 1px solid #333; }
    #msg { margin: .75rem 0; min-height: 1.2rem; color: var(--ok); font-size: .9rem; }
  </style>
</head>
<body>
  <h1>NAS Sync Monitor</h1>
  <p class="sub">Belső panel · <?= htmlspecialchars(BIND_HOST . ':' . BIND_PORT) ?> · csak LAN/VPN · <a href="docs.php" style="color:var(--accent)">Dokumentáció</a></p>
  <div id="msg"></div>

  <div class="grid">
    <div class="card">
      <h2>Állapot</h2>
      <div id="status-proc">Betöltés…</div>
      <div class="btns">
        <button type="button" onclick="ctl('start')">Start</button>
        <button type="button" class="secondary" onclick="ctl('restart')">Restart</button>
        <button type="button" class="danger" onclick="ctl('stop')">Stop</button>
        <button type="button" onclick="ctl('sync_now')">Sync most</button>
        <button type="button" class="secondary" onclick="refresh()">Frissítés</button>
      </div>
    </div>

    <div class="card">
      <h2>Mappa méretek</h2>
      <div id="status-sizes">—</div>
    </div>
  </div>

  <div class="grid" style="margin-top:1rem">
    <div class="card">
      <h2>Paraméterek (sync_video.env)</h2>
      <form id="cfg-form">
        <label>REMOTE_HOST</label>
        <input name="REMOTE_HOST" id="REMOTE_HOST">
        <label>REMOTE_PORT</label>
        <input name="REMOTE_PORT" id="REMOTE_PORT" type="number">
        <label>RSYNC_BWLIMIT (KB/s)</label>
        <input name="RSYNC_BWLIMIT" id="RSYNC_BWLIMIT" type="number">
        <label>POLL_INTERVAL_SEC (inotify nélkül)</label>
        <input name="POLL_INTERVAL_SEC" id="POLL_INTERVAL_SEC" type="number">
        <label>Szinkron mappák (sync_folders.conf)</label>
        <textarea name="folders_conf" id="folders_conf"></textarea>
        <div class="btns">
          <button type="submit">Mentés</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h2>Videó log</h2>
      <pre id="video-log">…</pre>
    </div>

    <div class="card">
      <h2>Webcam log</h2>
      <pre id="webcam-log">…</pre>
    </div>
  </div>

<script>
const msg = (t, ok=true) => { const el = document.getElementById('msg'); el.style.color = ok ? 'var(--ok)' : 'var(--warn)'; el.textContent = t; };

async function refresh() {
  const r = await fetch('api.php?action=status');
  const d = await r.json();
  const p = d.processes;
  document.getElementById('status-proc').innerHTML = `
    <div>Videó trigger: <span class="badge ${p.video_trigger?'on':'off'}">${p.video_trigger?'fut':'áll'}</span></div>
    <div style="margin-top:.4rem">Home trigger: <span class="badge ${p.homes_trigger?'on':'off'}">${p.homes_trigger?'fut':'áll'}</span></div>
    <div style="margin-top:.4rem;font-size:.8rem;color:var(--muted)">Frissítve: ${d.time}</div>
    ${p.rsync.length ? '<pre style="margin-top:.5rem">'+p.rsync.join('\n')+'</pre>' : ''}`;
  let tbl = '<table><tr><th>Forrás</th><th>Helyi</th><th>DSM2</th></tr>';
  d.folders.forEach(f => { tbl += `<tr><td>${f.src.split('/').pop()}</td><td>${f.local}</td><td>${f.remote}</td></tr>`; });
  document.getElementById('status-sizes').innerHTML = tbl + '</table>';
  document.getElementById('video-log').textContent = d.video_log;
  document.getElementById('webcam-log').textContent = d.webcam_log;
  const e = d.env;
  ['REMOTE_HOST','REMOTE_PORT','RSYNC_BWLIMIT','POLL_INTERVAL_SEC'].forEach(k => {
    const el = document.getElementById(k);
    if (el && e[k]) el.value = e[k];
  });
  document.getElementById('folders_conf').value = d.folders_conf;
}

async function ctl(cmd) {
  if (cmd === 'sync_now' && !confirm('Azonnali teljes videó sync indul. OK?')) return;
  const fd = new FormData(); fd.append('action','control'); fd.append('cmd', cmd);
  const r = await fetch('api.php', { method:'POST', body: fd });
  const d = await r.json();
  msg(d.ok ? (d.output || 'Kész') : (d.error || 'Hiba'), d.ok);
  setTimeout(refresh, 1500);
}

document.getElementById('cfg-form').onsubmit = async (ev) => {
  ev.preventDefault();
  const fd = new FormData(ev.target);
  fd.append('action', 'save');
  fd.append('env[REMOTE_HOST]', fd.get('REMOTE_HOST'));
  fd.append('env[REMOTE_PORT]', fd.get('REMOTE_PORT'));
  fd.append('env[RSYNC_BWLIMIT]', fd.get('RSYNC_BWLIMIT'));
  fd.append('env[POLL_INTERVAL_SEC]', fd.get('POLL_INTERVAL_SEC'));
  const r = await fetch('api.php', { method:'POST', body: fd });
  const d = await r.json();
  msg(d.ok ? d.message : d.error, d.ok);
  refresh();
};

refresh();
setInterval(refresh, 30000);
</script>
</body>
</html>
