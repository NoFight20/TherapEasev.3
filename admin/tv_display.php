<?php
// queue_display.php
include("../connection.php");

// ---------- Fetch Ads Config + Items from Realtime Database ----------
$adsConfig = [
    'enabled' => true,
    'interval_sec' => 10,
    'items' => []
];

try {
    $cfgSnap = $database->getReference('site/ads/config')->getSnapshot();
    if ($cfgSnap->exists() && is_array($cfgSnap->getValue())) {
        $cfg = $cfgSnap->getValue();
        $adsConfig['enabled']      = (bool)($cfg['enabled'] ?? true);
        $adsConfig['interval_sec'] = (int)($cfg['interval_sec'] ?? 10);
    }

    $itemsSnap = $database->getReference('site/ads/items')->getSnapshot();
    if ($itemsSnap->exists()) {
        $items = $itemsSnap->getValue();
        if (is_array($items)) {
            $tmp = [];
            foreach ($items as $key => $item) {
                if (!is_array($item)) continue;
                if (!($item['active'] ?? false)) continue;

                $imageUrl = trim((string)($item['image_url'] ?? ''));
                if ($imageUrl === '') continue;

                $tmp[] = [
                    'alt'       => (string)($item['alt'] ?? 'Announcement'),
                    'href'      => (string)($item['href'] ?? ''),
                    'image_url' => $imageUrl,
                    'order'     => (int)($item['order'] ?? 0),
                ];
            }
            usort($tmp, fn($a,$b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
            $adsConfig['items'] = $tmp;
        }
    }
} catch (Throwable $e) {
    // error_log('Ads load error: '.$e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Queue Window</title>
  <style>
   :root {
      --green:#0abf58;
      --card:#fff;
      --muted:#f7f7f7;
      --border:#ececec;
      --ink:#444;
      --ink-weak:#666;
      --blue:#0047ab;
      --shell:#f4f4f4;
      --radius:10px;
      --gap:18px;
      --sidebar-w:520px;
    }
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; background:var(--shell); margin:0; }

    .shell { width:95%; max-width:1600px; margin:20px auto; }
    .layout { display:flex; gap:var(--gap); align-items:flex-start; }
    .pane { background:var(--card); border-radius:var(--radius); box-shadow:0 0 10px rgba(0,0,0,.08); }

    .main { flex: 1 1 auto; padding: 18px 20px; min-height: 400px; position: relative; }
    .sidebar { flex: 0 0 var(--sidebar-w); max-width: var(--sidebar-w); padding: 18px; position: sticky; top: 16px; }

    .controls { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; gap:12px; }
    .sound-toggle {
      appearance:none; border:1px solid var(--border); padding:8px 12px; border-radius:8px;
      background:#f9f9f9; cursor:pointer; font-weight:600; color:#2b2b2b;
    }
    .sound-toggle[data-active="true"] { background:#e7f8ee; border-color:#bfe7d0; color:#0a7a3c; }
    .meta { color:var(--ink-weak); font-size:13px; margin-top:6px; }

    .section {
      background:var(--green); color:#fff; padding:10px 12px;
      font-weight:bold; font-size:20px; border-radius:6px;
      text-align:center; letter-spacing:.3px; display:none;
      margin-bottom:12px;
    }

    .dept { margin-bottom: 24px; min-height: 220px; display:flex; flex-direction:column; justify-content:space-between; }
    .dept .title { background:var(--green); color:#fff; padding:12px; text-align:center; border-radius:6px; font-weight:bold; font-size:20px; margin-bottom:12px; }
    .stats-vertical { display:flex; flex-direction:column; gap:14px; flex: 1; }
    .stat { background:var(--muted); border-radius:8px; padding:14px 16px; border:1px solid var(--border); flex:1; display:flex; flex-direction:column; justify-content:center; }
    .label { font-size:16px; color:var(--ink); margin-bottom:8px; }
    .highlight-box { font-size:28px; color:var(--blue); font-weight:bold; background:#e0e0e0; padding:16px; border-radius:6px; text-align:center; }

    .ads-header { color:#0a7a3c; font-weight:bold; font-size:22px; text-align:center; margin-bottom:14px; }
   .ad-frame {
  width: 100%;
  max-height: 600px; /* allow taller ads */
  border-radius: 16px;
  overflow: hidden;
  border: 2px solid var(--border);
  text-align: center;
}

.ad-frame img {
  width: 100%;
  height: auto;      /* keep natural proportions */
  object-fit: contain; /* ensure whole image is visible */
}


    @media (max-width: 1024px) {
      .layout { flex-direction:column; }
      .sidebar { position:static; width:100%; max-width:100%; }
    }
  </style>
</head>
<body>
  <div class="shell">
    <div class="layout">
      <!-- LEFT: QUEUE -->
      <div class="pane main">
        <div class="controls">
          <div class="section" id="screenTitle">ALL QUEUES</div>
          <button id="soundToggle" class="sound-toggle" data-active="false" aria-pressed="false" title="Enable sound alerts">
            🔇 Enable Sound
          </button>
        </div>
        <div id="container"></div>
        <div class="meta" id="pageInfo"></div>
        <div class="meta" id="lastUpdated">Last updated: —</div>
      </div>

      <!-- RIGHT: ADS -->
      <aside class="pane sidebar">
        <div class="ads-header">Announcements</div>
        <div class="ad-frame">
          <a id="adAnchor" href="#" target="_blank" rel="noopener">
            <img id="adImg" alt="Announcement" loading="lazy"/>
          </a>
        </div>
        <div class="meta" id="adsMeta"></div>
      </aside>
    </div>
  </div>

  <script>
/* =========================
  SOUND ENGINE 
========================= */
const SoundEngine = (() => {
  let ctx = null;
  let enabled = false;
  function ensureCtx() {
    if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (ctx.state === "suspended") ctx.resume();
  }
  const DING_SRC = "../sounds/ding.mp3";
  const dingAudio = new Audio(DING_SRC);
  dingAudio.preload = "auto";

  function playDing() {
    if (!enabled) return;
    ensureCtx();
    try {
      const node = dingAudio.cloneNode(true);
      node.currentTime = 0;
      node.play().catch(() => {});
    } catch {}
  }
  return {
    enable(){ enabled = true; ensureCtx(); },
    disable(){ enabled = false; },
    nowServing(){ playDing(); },
    nextPatient(){ playDing(); },
    get enabled(){ return enabled; }
  };
})();
const soundToggleBtn = document.getElementById('soundToggle');
soundToggleBtn.addEventListener('click', () => {
  if (SoundEngine.enabled) {
    SoundEngine.disable();
    soundToggleBtn.dataset.active = "false";
    soundToggleBtn.textContent = "🔇 Enable Sound";
  } else {
    SoundEngine.enable();
    soundToggleBtn.dataset.active = "true";
    soundToggleBtn.textContent = "🔊 Sound Enabled";
  }
});

/* =========================
   QUEUE (Left Pane)
========================= */
const container   = document.getElementById('container');
const screenTitle = document.getElementById('screenTitle');
const lastUpdatedEl = document.getElementById('lastUpdated');
const pageInfoEl  = document.getElementById('pageInfo');

const QUEUE_PAGE_SIZE = 2;
const QUEUE_ROTATE_MS = 5000;
let queuePages = [];
let queueIndex = 0;
let queueTimer = null;
const lastTokens = new Map();

function card(dept, nowT, nextT, priT){
  return `
    <div class="dept">
      <div class="title">${dept}</div>
      <div class="stats-vertical">
        <div class="stat"><div class="label">Next Patient</div><div class="highlight-box">${nextT || 'N/A'}</div></div>
        <div class="stat"><div class="label">Now Serving</div><div class="highlight-box">${nowT || 'N/A'}</div></div>
        <div class="stat"><div class="label">Priority</div><div class="highlight-box">${priT || 'N/A'}</div></div>
      </div>
    </div>`;
}

function chunk(arr, size) {
  const out = [];
  for (let i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
  return out;
}
function makePlaceholderDepts(count = 1) {
  // Always build exactly `count` placeholder dept cards
  return Array.from({ length: count }, () => ({
    department: '—',
    now_token: null,
    next_token: null,
    priority_token: null
  }));
}
function showQueuePage(i) {
  if (!queuePages.length) queuePages = [ makePlaceholderDepts() ];
  queueIndex = ((i % queuePages.length) + queuePages.length) % queuePages.length;
  const page = queuePages[queueIndex];
  container.innerHTML = page.map(d =>
    card(d.department || '—', d.now_token, d.next_token, d.priority_token)
  ).join('');
  const realDeptCount = page.filter(d => (d.department && d.department !== '—')).length;
  pageInfoEl.textContent = `Showing ${queueIndex + 1} of ${queuePages.length} page(s) • ${realDeptCount} department(s)`;
}
function stopQueueRotation() { if (queueTimer) { clearInterval(queueTimer); queueTimer = null; } }
function startQueueRotation() {
  stopQueueRotation();
  if (queuePages.length <= 1) return;
  queueTimer = setInterval(() => { showQueuePage(queueIndex + 1); }, QUEUE_ROTATE_MS);
}
function detectAndChime(depts) {
  for (const d of depts) {
    const name = String(d.department || 'N/A');
    const now  = d.now_token ?? null;
    const next = d.next_token ?? null;
    const prev = lastTokens.get(name) || { now: null, next: null };
    if (now && now !== prev.now) SoundEngine.nowServing();
    if (next && next !== prev.next) SoundEngine.nextPatient();
    lastTokens.set(name, { now, next });
  }
  for (const key of Array.from(lastTokens.keys())) {
    if (!depts.some(d => String(d.department || 'N/A') === key)) lastTokens.delete(key);
  }
}
function renderBoard(data) {
  const depts = Array.isArray(data?.departments) ? data.departments : [];
  depts.sort((a,b)=> String(a.department||'').localeCompare(String(b.department||'')));

  if (depts.length > 0) {
    // Normal behavior
    detectAndChime(depts);
    queuePages = chunk(depts, QUEUE_PAGE_SIZE);
  } else {
    // No entries → show exactly 1 placeholder table (not 2)
    queuePages = [ makePlaceholderDepts(1) ];
  }

  showQueuePage(0);
  startQueueRotation();
}
async function loadQueue() {
  try {
    const res = await fetch('queue_by_specialty.php' + window.location.search, { cache: 'no-store' });
    if (!res.ok) { container.innerHTML = `<div class="empty">Error ${res.status}</div>`; stopQueueRotation(); return; }
    const data = await res.json();
    renderBoard(data);
    lastUpdatedEl.textContent = 'Last updated: ' + new Date().toLocaleString();
  } catch {
    container.innerHTML = `<div class="empty">Error loading queue.</div>`;
    stopQueueRotation();
  }
}
loadQueue();
setInterval(loadQueue, 5000);

/* =========================
   ADS (Right Sidebar)
========================= */
window.ADS_CONFIG = <?php echo json_encode($adsConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const adImg    = document.getElementById('adImg');
const adAnchor = document.getElementById('adAnchor');
const adsMeta  = document.getElementById('adsMeta');
let adList = [];
let adIndex = 0;
let adTimer = null;

function renderSingleAd(item) {
  const frame = document.querySelector('.ad-frame');
  frame.innerHTML = ''; // clear old content

  if (!item) {
    frame.textContent = 'No announcement available';
    return;
  }

  // If ad is a video
  if (item.video_url) {
    const vid = document.createElement('video');
    vid.src = item.video_url + (item.video_url.includes('?') ? '&' : '?') + 'cb=' + Date.now();
    vid.controls = true;
    vid.autoplay = true;
    vid.loop = true;
    vid.muted = true; // autoplay without user interaction
    vid.style.width = '100%';
    vid.style.height = 'auto';
    frame.appendChild(vid);

    // Wrap video with link if href exists
    if (item.href) {
      const a = document.createElement('a');
      a.href = item.href;
      a.target = '_blank';
      a.rel = 'noopener';
      frame.innerHTML = '';
      a.appendChild(vid);
      frame.appendChild(a);
    }

  } else if (item.image_url) {
    // If ad is an image
    const img = document.createElement('img');
    img.src = item.image_url + (item.image_url.includes('?') ? '&' : '?') + 'cb=' + Date.now();
    img.alt = item.alt || 'Announcement';
    img.style.width = '100%';
    img.style.height = 'auto';
    img.style.objectFit = 'contain';

    if (item.href) {
      const a = document.createElement('a');
      a.href = item.href;
      a.target = '_blank';
      a.rel = 'noopener';
      a.appendChild(img);
      frame.appendChild(a);
    } else {
      frame.appendChild(img);
    }
  } else {
    frame.textContent = 'Unsupported ad format';
  }
}

function showAd(i) {
  adIndex = ((i % adList.length) + adList.length) % adList.length;
  renderSingleAd(adList[adIndex]);
}
function stopAdRotation() { if (adTimer) { clearInterval(adTimer); adTimer = null; } }
function startAdRotation(intervalSec) {
  const ms = Math.max(3000, (intervalSec || 10) * 1000);
  stopAdRotation();
  if (!Array.isArray(adList) || adList.length <= 1) return;
  adTimer = setInterval(() => { adIndex = (adIndex + 1) % adList.length; showAd(adIndex); }, ms);
  adsMeta.textContent = `Rotating ${adList.length} announcement(s) every ${(ms/1000)|0}s`;
}
(function initAds() {
  const cfg = window.ADS_CONFIG || {};
  if (cfg.enabled === false) { stopAdRotation(); renderSingleAd(null); adsMeta.textContent = 'Announcements disabled.'; return; }
  adList = Array.isArray(cfg.items) ? cfg.items : [];
  if (!adList.length) { renderSingleAd(null); adsMeta.textContent = 'No announcements available.'; return; }
  showAd(0); startAdRotation(Number(cfg.interval_sec || 10));
})();
  </script>
</body>
</html>
