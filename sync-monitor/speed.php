<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
deny_if_external();
?><!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Site sebesség — NAS Sync</title>
  <style>
    :root { --bg:#1a1d23; --card:#252a33; --text:#e8eaed; --muted:#9aa0a6; --accent:#6ba3ff; --ok:#5bd18a; }
    body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 1.25rem 1.5rem 2rem; max-width: 820px; }
    h1 { font-size: 1.25rem; margin: 0 0 .25rem; }
    .sub { color: var(--muted); font-size: .9rem; margin-bottom: 1rem; line-height: 1.45; }
    a { color: var(--accent); }
    button { background: var(--accent); color: #111; border: 0; border-radius: 6px; padding: .55rem 1rem; font-weight: 600; cursor: pointer; }
    button:disabled { opacity: .5; cursor: wait; }
    button.secondary { background: #3a4048; color: var(--text); }
    pre { background: var(--card); padding: 1rem; border-radius: 8px; font-size: .82rem; overflow: auto; white-space: pre-wrap; }
    table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: .88rem; }
    td, th { text-align: left; padding: .4rem .35rem; border-bottom: 1px solid #333; }
    th { color: var(--muted); font-weight: 600; }
    #msg { min-height: 1.2rem; color: var(--ok); margin: .75rem 0; }
    input, select { background: #16191f; border: 1px solid #444; color: #eee; padding: .35rem .5rem; border-radius: 4px; font: inherit; }
    .row { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin: .75rem 0; }
    .hint { font-size: .82rem; color: var(--muted); margin-top: .5rem; }
  </style>
</head>
<body>
  <h1>BP ↔ Ederics sebesség</h1>
  <p class="sub">
    S2S VPN · SSH (NAS↔NAS) + HTTP blob (külön port, nem blokkolja a monitort).<br>
    <strong>Tipp:</strong> mérd külön címkével a régi One router és az új Telekom uplink alatt — az előzmények megmaradnak.<br>
    <a href="index.php">← Monitor</a>
  </p>
  <div class="row">
    <label>Címke:
      <select id="label-preset" onchange="document.getElementById('label').value=this.value">
        <option value="">— válassz vagy írj —</option>
        <option value="One router (régi)">One router (régi)</option>
        <option value="Telekom (új)">Telekom (új)</option>
      </select>
      <input type="text" id="label" placeholder="pl. One router / Telekom" style="min-width:14rem;margin-left:.35rem">
    </label>
    <label>Méret (MB): <input type="number" id="mb" value="20" min="5" max="50" style="width:4rem"></label>
    <button type="button" id="run">Mérés indítása</button>
  </div>
  <p class="hint">A mérés ~1–3 perc, háttérben fut — közben a fő monitor használható.</p>
  <div id="msg"></div>
  <div id="summary"></div>
  <h2 style="font-size:1rem;color:var(--muted);margin-top:1.5rem">Előzmények</h2>
  <div id="history"></div>
  <pre id="raw" hidden></pre>

<script>
const msg = (t, ok=true) => {
  document.getElementById('msg').textContent = t;
  document.getElementById('msg').style.color = ok ? 'var(--ok)' : '#f07178';
};

function renderResult(d) {
  const p = d.ping_ms || {};
  const label = d.label ? `<tr><td colspan="2"><strong>${d.label}</strong> · ${d.at || ''}</td></tr>` : '';
  document.getElementById('summary').innerHTML = `
    <table>
      ${label}
      <tr><th>Ping</th><th>ms</th></tr>
      <tr><td>budberyl</td><td>${p.bp_beryl ?? '—'}</td></tr>
      <tr><td>ederberyl</td><td>${p.ederberyl ?? '—'}</td></tr>
      <tr><td>naszareti</td><td>${p.naszareti ?? '—'}</td></tr>
      <tr><th>Átvitel (${d.mb_per_test} MB)</th><th>Mbit/s</th></tr>
      <tr><td>Ederics → BP (SSH)</td><td><strong>${d.ssh_edercis_to_bp_mbps ?? '—'}</strong></td></tr>
      <tr><td>BP → Ederics (SSH)</td><td><strong>${d.ssh_bp_to_ederics_mbps ?? '—'}</strong></td></tr>
      <tr><td>Ederics → BP (HTTP)</td><td><strong>${d.http_edercis_to_bp_mbps ?? '—'}</strong></td></tr>
    </table>`;
  document.getElementById('raw').hidden = false;
  document.getElementById('raw').textContent = JSON.stringify(d, null, 2);
}

function renderHistory(items) {
  if (!items || !items.length) {
    document.getElementById('history').innerHTML = '<p class="hint">Még nincs mentett mérés.</p>';
    return;
  }
  const rows = items.map(h => `<tr>
    <td>${h.label || '—'}</td>
    <td>${(h.at || '').replace('T',' ').slice(0,19)}</td>
    <td>${h.ssh_edercis_to_bp_mbps ?? '—'}</td>
    <td>${h.ssh_bp_to_ederics_mbps ?? '—'}</td>
    <td>${h.http_edercis_to_bp_mbps ?? '—'}</td>
  </tr>`).join('');
  document.getElementById('history').innerHTML = `
    <table>
      <tr><th>Címke</th><th>Idő</th><th>E→BP SSH</th><th>BP→E SSH</th><th>E→BP HTTP</th></tr>
      ${rows}
    </table>`;
}

async function pollUntilDone() {
  for (let i = 0; i < 120; i++) {
    const r = await fetch('api.php?action=site_speed_poll');
    const d = await r.json();
    if (d.status === 'running') {
      msg(`Mérés fut… (${d.label || 'nincs címke'}) — ${i * 2}s`);
      await new Promise(res => setTimeout(res, 2000));
      continue;
    }
    if (d.status === 'done' && d.result) {
      renderResult(d.result);
      renderHistory(d.history || []);
      msg('Kész — ' + (d.result.label || ''));
      return;
    }
    if (d.status === 'error') {
      throw new Error(d.result?.error || 'Mérés hiba');
    }
    renderHistory(d.history || []);
    msg('Nincs aktív mérés', false);
    return;
  }
  throw new Error('Timeout — nézd /tmp/sync_site_speed_worker.log a NAS-on');
}

async function loadHistory() {
  const r = await fetch('api.php?action=site_speed_poll');
  const d = await r.json();
  if (d.status === 'running') {
    document.getElementById('run').disabled = true;
    msg('Mérés már fut…');
    await pollUntilDone();
    document.getElementById('run').disabled = false;
    return;
  }
  if (d.status === 'done' && d.result) renderResult(d.result);
  renderHistory(d.history || []);
}

document.getElementById('run').onclick = async () => {
  const btn = document.getElementById('run');
  const mb = document.getElementById('mb').value || 20;
  const label = document.getElementById('label').value.trim();
  btn.disabled = true;
  document.getElementById('summary').innerHTML = '';
  try {
    const fd = new FormData();
    fd.append('action', 'site_speed');
    fd.append('mb', mb);
    fd.append('label', label);
    const r = await fetch('api.php?action=site_speed&label=' + encodeURIComponent(label) + '&mb=' + encodeURIComponent(mb), { method: 'POST', body: fd });
    const start = await r.json();
    if (start.error) throw new Error(start.error);
    if (start.status === 'running') {
      msg('Már fut egy mérés — várakozás…');
    } else {
      msg('Háttérmérés elindult…');
    }
    await pollUntilDone();
  } catch (e) {
    msg(e.message || 'Hiba', false);
  } finally {
    btn.disabled = false;
  }
};

loadHistory();
</script>
</body>
</html>
