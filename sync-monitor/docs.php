<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
deny_if_external();

$current = $_GET['f'] ?? 'README.md';
if (!in_array($current, list_docs(), true)) {
    $current = 'README.md';
}
$content = read_doc($current);
$docs = list_docs();
?><!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dokumentáció — NAS Sync</title>
  <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
  <style>
    :root { --bg:#1a1d23; --card:#252a33; --text:#e8eaed; --muted:#9aa0a6; --accent:#6ba3ff; }
    * { box-sizing: border-box; }
    body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; display: flex; min-height: 100vh; }
    nav { width: 220px; background: #16191f; padding: 1rem; flex-shrink: 0; border-right: 1px solid #333; }
    nav h2 { font-size: .85rem; color: var(--muted); margin: 0 0 .75rem; text-transform: uppercase; letter-spacing: .05em; }
    nav a { display: block; color: var(--text); text-decoration: none; padding: .45rem .5rem; border-radius: 6px; font-size: .9rem; margin-bottom: .2rem; }
    nav a:hover, nav a.active { background: var(--card); color: var(--accent); }
    nav .back { margin-top: 1.5rem; color: var(--muted); font-size: .85rem; }
    main { flex: 1; padding: 1.25rem 2rem 2rem; overflow: auto; max-width: 900px; }
    #doc { line-height: 1.55; font-size: .95rem; }
    #doc h1 { font-size: 1.5rem; border-bottom: 1px solid #333; padding-bottom: .4rem; }
    #doc h2 { font-size: 1.15rem; color: var(--accent); margin-top: 1.5rem; }
    #doc h3 { font-size: 1rem; }
    #doc pre { background: #16191f; padding: .75rem; border-radius: 6px; overflow: auto; font-size: .8rem; }
    #doc code { background: #16191f; padding: .1rem .35rem; border-radius: 4px; font-size: .85em; }
    #doc pre code { background: none; padding: 0; }
    #doc table { border-collapse: collapse; width: 100%; margin: .75rem 0; font-size: .88rem; }
    #doc th, #doc td { border: 1px solid #3a4048; padding: .4rem .55rem; text-align: left; }
    #doc th { background: #16191f; }
    #doc a { color: var(--accent); }
    #doc blockquote { border-left: 3px solid var(--accent); margin: .5rem 0; padding-left: 1rem; color: var(--muted); }
  </style>
</head>
<body>
  <nav>
    <h2>Dokumentáció</h2>
    <?php foreach ($docs as $doc): ?>
      <a href="docs.php?f=<?= urlencode($doc) ?>" class="<?= $doc === $current ? 'active' : '' ?>"><?= htmlspecialchars($doc) ?></a>
    <?php endforeach; ?>
    <a class="back" href="index.php">← Monitor</a>
  </nav>
  <main>
    <div id="doc"></div>
  </main>
  <script>
    marked.setOptions({ breaks: true, gfm: true });
    document.getElementById('doc').innerHTML = marked.parse(<?= json_encode($content, JSON_UNESCAPED_UNICODE) ?>);
    // Belső README linkek → docs.php
    document.querySelectorAll('#doc a[href$=".md"]').forEach(a => {
      const name = a.getAttribute('href').split('/').pop();
      if (name.startsWith('README')) a.href = 'docs.php?f=' + encodeURIComponent(name);
    });
  </script>
</body>
</html>
