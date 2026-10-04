<?php header("Content-Type: application/javascript"); ?>
"use strict";

// ══════════════════════════════════════════════════════════════════════════════
// FEATURE 1: USER DRAWING ON TOP
// ══════════════════════════════════════════════════════════════════════════════
const userDrawState = {
  active: false,
  tool: 'pen',      // pen | highlighter | eraser
  color: '#FF3131',
  size: 4,
  strokes: [],       // [{tool, color, size, pts:[{x,y}]}]
  current: null,
  redoStack: [],
};

let _udcEl = null;  // the user-draw-canvas element
let _udcCtx = null;

function initUserDraw() {
  _udcEl = $('user-draw-canvas');
  if (!_udcEl) return;
  _udcCtx = _udcEl.getContext('2d');

  $('dtb-toggle').addEventListener('click', () => toggleUserDraw(!userDrawState.active));

  $('dtb-pen').addEventListener('click', () => setUserTool('pen'));
  $('dtb-hi').addEventListener('click', () => setUserTool('highlighter'));
  $('dtb-eraser').addEventListener('click', () => setUserTool('eraser'));

  $('dtb-color').addEventListener('input', e => { userDrawState.color = e.target.value; });
  $('dtb-size').addEventListener('input', e => { userDrawState.size = parseInt(e.target.value); });

  $('dtb-undo').addEventListener('click', undoUserDraw);
  $('dtb-clear').addEventListener('click', () => { if(confirm('Clear all your drawings?')){ userDrawState.strokes=[]; userDrawState.redoStack=[]; redrawUserCanvas(); } });

  // Keyboard: D to toggle, Ctrl+Z to undo
  document.addEventListener('keydown', e => {
    if (e.target.tagName==='INPUT' || e.target.tagName==='TEXTAREA') return;
    if (e.key==='d' || e.key==='D') toggleUserDraw(!userDrawState.active);
    if ((e.ctrlKey||e.metaKey) && e.key==='z') undoUserDraw();
  });

  _udcEl.addEventListener('pointerdown', onUserDrawStart);
  _udcEl.addEventListener('pointermove', onUserDrawMove);
  _udcEl.addEventListener('pointerup',   onUserDrawEnd);
  _udcEl.addEventListener('pointerleave', onUserDrawEnd);
  _udcEl.addEventListener('contextmenu', e => e.preventDefault());
}

function toggleUserDraw(on) {
  userDrawState.active = on;
  const tb = $('draw-toolbar');
  if (tb) tb.classList.toggle('visible', on);
  if (_udcEl) _udcEl.classList.toggle('active', on);
  $('dtb-toggle').classList.toggle('active', on);
  const sp = $('slide-panel');
  if (sp) sp.style.cursor = on ? 'none' : '';
}

function setUserTool(tool) {
  userDrawState.tool = tool;
  ['dtb-pen','dtb-hi','dtb-eraser'].forEach(id => { $(id).classList.remove('active'); });
  const map = { pen:'dtb-pen', highlighter:'dtb-hi', eraser:'dtb-eraser' };
  if (map[tool]) $(map[tool]).classList.add('active');
  _udcEl.style.cursor = tool === 'eraser' ? 'cell' : 'crosshair';
}

function _udcCoord(e) {
  if (!_udcEl) return {x:0,y:0};
  const r = _udcEl.getBoundingClientRect();
  const dpr = window.devicePixelRatio || 1;
  return { x: (e.clientX - r.left) * dpr, y: (e.clientY - r.top) * dpr };
}

function onUserDrawStart(e) {
  if (!userDrawState.active) return;
  e.preventDefault();
  _udcEl.setPointerCapture(e.pointerId);
  const pt = _udcCoord(e);
  userDrawState.current = {
    tool: userDrawState.tool,
    color: userDrawState.color,
    size: userDrawState.size,
    pts: [pt]
  };
  userDrawState.redoStack = [];
}

function onUserDrawMove(e) {
  if (!userDrawState.active || !userDrawState.current) return;
  e.preventDefault();
  userDrawState.current.pts.push(_udcCoord(e));
  redrawUserCanvas();
}

function onUserDrawEnd(e) {
  if (!userDrawState.current) return;
  if (userDrawState.current.pts.length > 0) userDrawState.strokes.push(userDrawState.current);
  userDrawState.current = null;
  redrawUserCanvas();
}

function undoUserDraw() {
  if (!userDrawState.strokes.length) return;
  userDrawState.redoStack.push(userDrawState.strokes.pop());
  redrawUserCanvas();
}

function redrawUserCanvas() {
  if (!_udcCtx || !_udcEl) return;
  const ctx = _udcCtx;
  ctx.clearRect(0, 0, _udcEl.width, _udcEl.height);

  const allStrokes = [...userDrawState.strokes];
  if (userDrawState.current) allStrokes.push(userDrawState.current);

  for (const stroke of allStrokes) {
    const pts = stroke.pts;
    if (!pts || pts.length === 0) continue;
    ctx.save();
    if (stroke.tool === 'eraser') {
      ctx.globalCompositeOperation = 'destination-out';
      ctx.strokeStyle = 'rgba(0,0,0,1)';
      ctx.lineWidth = stroke.size * 4;
    } else if (stroke.tool === 'highlighter') {
      ctx.globalCompositeOperation = 'source-over';
      ctx.strokeStyle = stroke.color + '55';
      ctx.lineWidth = stroke.size * 6;
    } else {
      ctx.globalCompositeOperation = 'source-over';
      ctx.strokeStyle = stroke.color;
      ctx.lineWidth = stroke.size;
    }
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.beginPath();
    if (pts.length === 1) {
      ctx.arc(pts[0].x, pts[0].y, ctx.lineWidth/2, 0, Math.PI*2);
      ctx.fill();
    } else {
      ctx.moveTo(pts[0].x, pts[0].y);
      for (let i = 1; i < pts.length - 1; i++) {
        const mx = (pts[i].x + pts[i+1].x) / 2;
        const my = (pts[i].y + pts[i+1].y) / 2;
        ctx.quadraticCurveTo(pts[i].x, pts[i].y, mx, my);
      }
      ctx.lineTo(pts[pts.length-1].x, pts[pts.length-1].y);
      ctx.stroke();
    }
    ctx.restore();
  }
}

// ══════════════════════════════════════════════════════════════════════════════
// FEATURE 2: CHAPTER / SLIDE NAVIGATOR
// ══════════════════════════════════════════════════════════════════════════════
let _chapterThumbs = new Map(); // sid -> dataURL

function buildChapterNav() {
  const list = $('chapter-list');
  const empty = $('chapter-empty');
  const countEl = $('chapter-count');
  if (!list || !stateManager) return;

  const tl = stateManager.slideTimeline;
  if (!tl || !tl.length) { if (empty) empty.style.display = ''; return; }
  if (empty) empty.style.display = 'none';

  // De-duplicate: keep first occurrence of each sid
  const seen = new Set();
  const slides = tl.filter(sl => {
    if (seen.has(sl.sid)) return false;
    seen.add(sl.sid); return true;
  });
  if (countEl) countEl.textContent = slides.length + ' slides';

  list.innerHTML = '';
  slides.forEach((sl, i) => {
    // Convert the slide's event time back to video seconds (same rule as playback)
    const evMs = sl.timeMs;
    const videoSec = timeline ? timeline.eventToVideoMs(evMs) / 1000 : 0;

    const item = document.createElement('div');
    item.className = 'chapter-item';
    item.dataset.sid = sl.sid;
    item.dataset.sec = videoSec.toFixed(1);

    const thumbWrap = document.createElement('div');
    thumbWrap.className = 'chapter-thumb';
    if (_chapterThumbs.has(sl.sid)) {
      const img = document.createElement('img');
      img.src = _chapterThumbs.get(sl.sid);
      img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
      thumbWrap.appendChild(img);
    } else {
      thumbWrap.innerHTML = `<div class="ct-placeholder">🖼</div>`;
    }

    const info = document.createElement('div');
    info.className = 'chapter-info';
    info.innerHTML = `
      <div class="chapter-ts">${fmtTime(videoSec)}</div>
      <div class="chapter-title">Slide ${i+1}</div>
      <div class="chapter-sub">Event ${evMs.toLocaleString()} ms</div>`;

    item.appendChild(thumbWrap);
    item.appendChild(info);
    item.addEventListener('click', () => {
      seekToNoteTime(parseFloat(item.dataset.sec));
      highlightChapterItem(sl.sid);
    });
    list.appendChild(item);
  });

  highlightChapterAtTime();
}

function highlightChapterItem(sid) {
  document.querySelectorAll('.chapter-item').forEach(el => {
    el.classList.toggle('active', el.dataset.sid === sid);
  });
}

function highlightChapterAtTime() {
  if (!stateManager) return;
  highlightChapterItem(stateManager.currentSid);
}

// ── Offscreen infra for rendering REAL slide thumbnails (background image +
// actual replayed strokes), fully isolated from the live stateManager/timeline
// so generating thumbnails never disturbs what's on screen / incremental
// playback cursor.
let _thumbInfra = null;
function _initThumbInfra() {
  if (_thumbInfra || !timeline || !stateManager) return;
  const tl2 = Object.assign(Object.create(TimelineEngine.prototype), {
    events: timeline.events, minTime: timeline.minTime, maxTime: timeline.maxTime,
    t0: timeline.t0, eventSpanUs: timeline.eventSpanUs, divider: timeline.divider,
    syncLagMs: timeline.syncLagMs, lastEventIndex: -1
  });
  const sm2 = new StateManager(tl2, 144, 81);
  sm2.buildSlideIndex();
  // Mirror the live player's two-layer stack (background canvas underneath,
  // transparent stroke canvas on top) — CanvasRenderer.render() clears its
  // own canvas to transparent every frame, so the background must live on a
  // SEPARATE canvas and get composited in afterwards, not drawn first then
  // wiped by render().
  const bg = document.createElement('canvas'); bg.width = 144; bg.height = 81;
  const overlay = document.createElement('canvas'); overlay.width = 144; overlay.height = 81;
  const out = document.createElement('canvas'); out.width = 144; out.height = 81;
  _thumbInfra = {
    bgCtx: bg.getContext('2d'), overlay, sm: sm2, cr: new CanvasRenderer(overlay),
    out, outCtx: out.getContext('2d')
  };
}

// Render one slide's actual content (background image, if any, + the strokes
// drawn on it up to renderMs) into a small offscreen canvas and return a
// dataURL — this is what the navigator shows, instead of a generic icon.
async function generateSlideThumb(sid, renderMs, url) {
  _initThumbInfra();
  if (!_thumbInfra) return null;
  const { bgCtx, overlay, sm, cr, out, outCtx } = _thumbInfra;
  const W = 144, H = 81;
  bgCtx.clearRect(0, 0, W, H);
  bgCtx.fillStyle = '#202022';
  bgCtx.fillRect(0, 0, W, H);

  let annoLayout = { x: 0, y: 0, w: W, h: H };
  if (url && pdfRenderer) {
    try {
      if (!pdfRenderer.imgCache.has(url)) await pdfRenderer.prefetch(url);
      const bmp = pdfRenderer.imgCache.get(url);
      if (bmp) {
        const scale = Math.min(W / bmp.width, H / bmp.height);
        const w = bmp.width * scale, h = bmp.height * scale;
        const x = (W - w) / 2, y = (H - h) / 2;
        bgCtx.drawImage(bmp, x, y, w, h);
        annoLayout = { x, y, w, h };
      }
    } catch (_) {}
  }

  try {
    sm.rebuildState(renderMs);
    cr.invalidate();
    cr.render(sm, annoLayout, renderMs);
  } catch (_) {}

  outCtx.clearRect(0, 0, W, H);
  outCtx.drawImage(_thumbInfra.bgCtx.canvas, 0, 0);
  outCtx.drawImage(overlay, 0, 0);
  return out.toDataURL('image/jpeg', 0.75);
}

// Capture thumbnails async in the background (low priority). Runs against the
// isolated offscreen state/timeline above, so it's safe to do this even while
// the real lesson is actively playing.
async function captureChapterThumbs() {
  if (!stateManager || !timeline || !videoEl.duration) return;
  const tl = stateManager.slideTimeline;
  if (!tl || !tl.length) return;

  // For each sid, use the LATEST point at which it's on screen (right before
  // the next slide switch, across every visit) so the thumbnail reflects the
  // fullest set of annotations drawn on that board, not just its first frame.
  const cutoffBySid = new Map();
  const urlBySid = new Map();
  for (let i = 0; i < tl.length; i++) {
    const sl = tl[i];
    const cutoff = (i + 1 < tl.length) ? tl[i + 1].timeMs : timeline.getDurationMs();
    if (!cutoffBySid.has(sl.sid) || cutoff > cutoffBySid.get(sl.sid)) cutoffBySid.set(sl.sid, cutoff);
    if (sl.url) urlBySid.set(sl.sid, sl.url);
  }

  const seen = new Set();
  const slides = tl.filter(sl => {
    if (seen.has(sl.sid)) return false;
    seen.add(sl.sid); return true;
  });

  for (const sl of slides.slice(0, 60)) {
    if (_chapterThumbs.has(sl.sid)) continue;
    try {
      const renderMs = cutoffBySid.get(sl.sid) ?? sl.timeMs;
      const dataUrl = await generateSlideThumb(sl.sid, renderMs, urlBySid.get(sl.sid));
      if (dataUrl) _chapterThumbs.set(sl.sid, dataUrl);
    } catch (_) {}
    await new Promise(r => setTimeout(r, 30));
  }
  buildChapterNav();
}

// ══════════════════════════════════════════════════════════════════════════════
// FEATURE 3: MULTI-DEVICE NOTE SYNC (BroadcastChannel + sync key)
// ══════════════════════════════════════════════════════════════════════════════
let _syncChannel = null;
let _syncPeers = 0;
const _syncId = Math.random().toString(36).slice(2);

function initNoteSync() {
  if (!window.BroadcastChannel) return;
  const key = _notesKey || 'ua_notes_default';
  _syncChannel = new BroadcastChannel('ua_player_sync_' + key);

  _syncChannel.onmessage = (e) => {
    const { type, notes, from } = e.data || {};
    if (from === _syncId) return; // ignore own messages

    if (type === 'notes_update') {
      _notesCache = notes || [];
      saveNotes();   // handles both DB and localStorage
      renderNotesList();
      updateSyncBadge(true, 'synced from another tab');
    } else if (type === 'ping') {
      _syncPeers++;
      updateSyncBadge(true, _syncPeers + ' tab(s) connected');
      _syncChannel.postMessage({ type: 'pong', from: _syncId });
    } else if (type === 'pong') {
      _syncPeers++;
      updateSyncBadge(true, _syncPeers + ' tab(s) connected');
    }
  };

  setTimeout(() => { _syncChannel.postMessage({ type: 'ping', from: _syncId }); }, 500);
  updateSyncBadge(false, 'looking…');
}

function broadcastNotesUpdate() {
  if (!_syncChannel) return;
  _syncChannel.postMessage({ type: 'notes_update', notes: _notesCache, from: _syncId });
}

function updateSyncBadge(online, tooltip) {
  const badge = $('sync-badge');
  if (!badge) return;
  badge.classList.toggle('offline', !online);
  badge.title = tooltip || '';
}

// ══════════════════════════════════════════════════════════════════════════════
// FEATURE 4: HIGHLIGHT REEL EXPORT
// ══════════════════════════════════════════════════════════════════════════════
let _reelSelectionMode = false;
let _reelSelected = new Set();

function initHighlightReel() {
  const btn = $('reel-select-btn');
  if (btn) btn.addEventListener('click', toggleReelMode);

  $('reel-export-btn').addEventListener('click', startReelExport);
  $('reel-cancel-btn').addEventListener('click', () => {
    $('reel-modal').classList.add('hidden');
    exitReelMode();
  });
}

function toggleReelMode() {
  _reelSelectionMode = !_reelSelectionMode;
  const btn = $('reel-select-btn');
  if (btn) btn.classList.toggle('active', _reelSelectionMode);
  if (_reelSelectionMode) enterReelSelectionMode();
  else exitReelMode();
}

function enterReelSelectionMode() {
  _reelSelected.clear();
  document.querySelectorAll('.note-item').forEach(el => {
    el.classList.add('sel-mode');
    el.addEventListener('click', onReelNoteClick, { capture: true });
  });
  const btn = $('reel-select-btn');
  if (btn) {
    btn.textContent = '✅ Done';
    btn.title = 'Confirm selection for Highlight Reel';
    btn.removeEventListener('click', toggleReelMode);
    btn.addEventListener('click', openReelModal, { once: true });
  }
}

function onReelNoteClick(e) {
  e.stopImmediatePropagation();
  const id = this.dataset.id;
  if (_reelSelected.has(id)) { _reelSelected.delete(id); this.classList.remove('checked'); }
  else { _reelSelected.add(id); this.classList.add('checked'); }
}

function exitReelMode() {
  _reelSelectionMode = false;
  _reelSelected.clear();
  document.querySelectorAll('.note-item').forEach(el => {
    el.classList.remove('sel-mode', 'checked');
    el.removeEventListener('click', onReelNoteClick, { capture: true });
  });
  const btn = $('reel-select-btn');
  if (btn) {
    btn.textContent = '🎬';
    btn.title = 'Select notes for Highlight Reel';
    btn.classList.remove('active');
    btn.removeEventListener('click', openReelModal);
    btn.addEventListener('click', toggleReelMode);
  }
}

function openReelModal() {
  const selected = _notesCache.filter(n => _reelSelected.has(n.id));
  if (!selected.length) { alert('Select at least one note first.'); enterReelSelectionMode(); return; }
  const win = parseInt($('reel-window')?.value || '30');
  const totalSec = selected.length * win * 2;

  $('reel-selection-info').textContent = `${selected.length} note(s) selected`;
  $('reel-duration-info').textContent = `Est. reel duration: ~${fmtTime(totalSec)}`;
  $('reel-progress').classList.add('hidden');
  $('reel-progress-fill').style.width = '0%';
  $('reel-modal').classList.remove('hidden');

  const btn = $('reel-select-btn');
  if (btn) {
    btn.textContent = '🎬';
    btn.classList.remove('active');
    btn.removeEventListener('click', openReelModal);
    btn.addEventListener('click', toggleReelMode);
  }
  document.querySelectorAll('.note-item').forEach(el => {
    el.classList.remove('sel-mode');
    el.removeEventListener('click', onReelNoteClick, { capture: true });
  });
  _reelSelectionMode = false;
}

async function startReelExport() {
  const selected = _notesCache.filter(n => _reelSelected.has(n.id));
  if (!selected.length) { alert('No notes selected.'); return; }
  if (!videoEl || !videoEl.src) { alert('No video loaded.'); return; }

  const win = parseInt($('reel-window')?.value || '30');
  const prog = $('reel-progress');
  const progFill = $('reel-progress-fill');
  const progLabel = $('reel-progress-label');
  if (prog) prog.classList.remove('hidden');

  $('reel-export-btn').disabled = true;

  const dur = videoEl.duration || 0;
  const intervals = selected.map(n => ({
    start: Math.max(0, n.videoSec - win),
    end:   Math.min(dur, n.videoSec + win),
    label: n.ts
  }));

  // Merge overlapping intervals
  intervals.sort((a,b) => a.start - b.start);
  const merged = [intervals[0]];
  for (const iv of intervals.slice(1)) {
    const last = merged[merged.length-1];
    if (iv.start <= last.end) last.end = Math.max(last.end, iv.end);
    else merged.push({...iv});
  }

  const composite = document.createElement('canvas');
  composite.width  = slideCanvas.width;
  composite.height = slideCanvas.height;
  const cctx = composite.getContext('2d');

  if (!window.MediaRecorder) {
    alert('MediaRecorder not supported in this browser. Try Chrome/Edge.');
    $('reel-export-btn').disabled = false;
    return;
  }

  const stream = composite.captureStream(25);
  const mimeType = ['video/webm;codecs=vp9','video/webm;codecs=vp8','video/webm','video/mp4']
    .find(m => MediaRecorder.isTypeSupported(m)) || 'video/webm';
  const recorder = new MediaRecorder(stream, { mimeType, videoBitsPerSecond: 2_500_000 });
  const chunks = [];
  recorder.ondataavailable = e => { if (e.data.size) chunks.push(e.data); };

  const wasPlaying = !videoEl.paused;
  const savedTime  = videoEl.currentTime;
  videoEl.pause();
  videoEl.muted = true;

  recorder.start(100);

  let clipsDone = 0;
  for (const iv of merged) {
    if (progLabel) progLabel.textContent = `Clip ${clipsDone+1}/${merged.length} (${fmtTime(iv.start)} → ${fmtTime(iv.end)})`;

    videoEl.currentTime = iv.start;
    await new Promise(r => videoEl.addEventListener('seeked', r, {once:true}));

    await videoEl.play().catch(()=>{});
    const ivDur = iv.end - iv.start;

    await new Promise(resolve => {
      const startWall = performance.now();
      function drawFrame() {
        cctx.clearRect(0,0,composite.width,composite.height);
        cctx.drawImage(slideCanvas, 0, 0);
        cctx.drawImage(drawCanvas,  0, 0);
        cctx.drawImage(_udcEl || slideCanvas, 0, 0);
        const elapsed = (performance.now() - startWall) / 1000;
        const pct = Math.min(100, ((clipsDone + elapsed/ivDur) / merged.length) * 100);
        if (progFill) progFill.style.width = pct + '%';
        if (elapsed < ivDur && videoEl.currentTime < iv.end) requestAnimationFrame(drawFrame);
        else { videoEl.pause(); resolve(); }
      }
      requestAnimationFrame(drawFrame);
    });

    clipsDone++;
  }

  recorder.stop();
  await new Promise(r => recorder.onstop = r);

  videoEl.muted = false;
  videoEl.currentTime = savedTime;
  await new Promise(r => videoEl.addEventListener('seeked', r, {once:true}));
  if (wasPlaying) videoEl.play().catch(()=>{});

  const blob = new Blob(chunks, {type: mimeType});
  const url  = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'highlight-reel-' + new Date().toISOString().slice(0,10) + '.webm';
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 8000);

  if (progFill) progFill.style.width = '100%';
  if (progLabel) progLabel.textContent = '✓ Done! Downloading…';
  setTimeout(() => { $('reel-modal').classList.add('hidden'); _reelSelected.clear(); }, 2000);
  $('reel-export-btn').disabled = false;
}

// ── Sidebar tabs wiring ───────────────────────────────────────────────────────
function initSidebarTabs() {
  document.querySelectorAll('.stab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.stab-btn').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.stab-pane').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      const target = $(btn.dataset.stab);
      if (target) target.classList.add('active');
      if (btn.dataset.stab === 'stab-chapters') {
        buildChapterNav();
        setTimeout(captureChapterThumbs, 500);
      }
    });
  });
}

// ══════════════════════════════════════════════════════════════════════════════
// FEATURE: SEEK THUMBNAIL PREVIEW
// ══════════════════════════════════════════════════════════════════════════════
const _thumbCache = new Map(); // videoSec (rounded to 5s) -> dataURL
let _thumbGenBusy = false;
let _thumbHideTO = null;

function initSeekThumbnail() {
  const container   = $('seek-bar-container');
  const preview     = $('seek-thumbnail-preview');
  const thumbCanvas = $('seek-thumb-canvas');
  const thumbTime   = $('seek-thumb-time');
  const bar         = $('seek-bar');
  if (!container || !preview || !thumbCanvas || !bar) return;

  const tctx = thumbCanvas.getContext('2d');

  bar.addEventListener('mousemove', async (e) => {
    const r   = bar.getBoundingClientRect();
    const pos = Math.max(0, Math.min(1, (e.clientX - r.left) / r.width));
    const sec = pos * parseFloat(bar.max || 0);

    const containerRect = container.getBoundingClientRect();
    const relX = e.clientX - containerRect.left;
    const clampedLeft = Math.max(80, Math.min(containerRect.width - 80, relX));
    preview.style.left = clampedLeft + 'px';

    if (thumbTime) thumbTime.textContent = fmtTime(sec);
    preview.classList.add('show');

    if (_thumbHideTO) { clearTimeout(_thumbHideTO); _thumbHideTO = null; }

    const key = Math.round(sec / 5) * 5; // quantize to 5-second buckets
    if (_thumbCache.has(key)) _drawThumb(tctx, thumbCanvas, _thumbCache.get(key));
    else _generateThumb(key, tctx, thumbCanvas);
  });

  bar.addEventListener('mouseleave', () => {
    _thumbHideTO = setTimeout(() => preview.classList.remove('show'), 120);
  });

  bar.addEventListener('mouseenter', () => {
    if (_thumbHideTO) { clearTimeout(_thumbHideTO); _thumbHideTO = null; }
  });
}

function _drawThumb(tctx, canvas, dataUrl) {
  const img = new Image();
  img.onload = () => {
    tctx.clearRect(0, 0, canvas.width, canvas.height);
    const scale = Math.min(canvas.width / img.width, canvas.height / img.height);
    const w = img.width * scale, h = img.height * scale;
    const x = (canvas.width - w) / 2, y = (canvas.height - h) / 2;
    tctx.fillStyle = '#111';
    tctx.fillRect(0, 0, canvas.width, canvas.height);
    tctx.drawImage(img, x, y, w, h);
  };
  img.src = dataUrl;
}

async function _generateThumb(videoSec, tctx, canvas) {
  if (_thumbGenBusy || !videoEl || !timeline || !stateManager || !pdfRenderer) return;
  if (!videoEl.duration || videoSec > videoEl.duration) return;
  _thumbGenBusy = true;

  try {
    const vidDurMs = videoEl.duration * 1000;
    const eventMs  = timeline.videoToEventMs(videoSec * 1000, vidDurMs);
    const slideInfo = stateManager.getSlideAtTime(eventMs);

    const offW = slideCanvas.width, offH = slideCanvas.height;
    const off  = document.createElement('canvas');
    off.width  = offW; off.height = offH;
    const octx = off.getContext('2d');

    if (slideInfo && slideInfo.url) {
      if (pdfRenderer.imgCache.has(slideInfo.url)) {
        const bmp = pdfRenderer.imgCache.get(slideInfo.url);
        const scale = Math.min(offW / bmp.width, offH / bmp.height);
        const w = bmp.width * scale, h = bmp.height * scale;
        octx.fillStyle = '#111';
        octx.fillRect(0, 0, offW, offH);
        octx.drawImage(bmp, (offW - w) / 2, (offH - h) / 2, w, h);
      } else {
        try {
          const resp = await fetch(slideInfo.url);
          if (resp.ok) {
            const blob  = await resp.blob();
            const bmp   = await createImageBitmap(blob);
            const scale = Math.min(offW / bmp.width, offH / bmp.height);
            const w = bmp.width * scale, h = bmp.height * scale;
            octx.fillStyle = '#111';
            octx.fillRect(0, 0, offW, offH);
            octx.drawImage(bmp, (offW - w) / 2, (offH - h) / 2, w, h);
            pdfRenderer.imgCache.set(slideInfo.url, bmp);
          }
        } catch (_) {
          octx.fillStyle = pdfRenderer.bgColor || '#111';
          octx.fillRect(0, 0, offW, offH);
        }
      }
    } else {
      octx.fillStyle = pdfRenderer.bgColor || '#111';
      octx.fillRect(0, 0, offW, offH);
    }

    const thumbC = document.createElement('canvas');
    thumbC.width = 160; thumbC.height = 90;
    thumbC.getContext('2d').drawImage(off, 0, 0, 160, 90);
    const dataUrl = thumbC.toDataURL('image/jpeg', 0.75);

    _thumbCache.set(videoSec, dataUrl);
    _drawThumb(tctx, canvas, dataUrl);
  } catch (_) {}

  _thumbGenBusy = false;
}

// ══════════════════════════════════════════════════════════════════════════════
// FEATURE: NOTE MARKERS ON SEEK BAR
// ══════════════════════════════════════════════════════════════════════════════
function renderNoteMarkers() {
  const layer = $('note-markers-layer');
  const bar   = $('seek-bar');
  if (!layer || !bar) return;

  layer.innerHTML = '';
  if (!_notesCache.length || !videoEl || !videoEl.duration) return;

  const dur = parseFloat(bar.max) || videoEl.duration || 1;

  _notesCache.forEach(note => {
    const pct = Math.max(0, Math.min(100, (note.videoSec / dur) * 100));
    const dot = document.createElement('div');
    dot.className = `note-marker ${note.type}`;
    dot.style.left = pct + '%';
    dot.title = note.ts + (note.text ? ' — ' + note.text.slice(0, 40) : '');

    const tip = document.createElement('div');
    tip.className = 'note-marker-tooltip';
    tip.textContent = (note.text || note.type).slice(0, 30);
    dot.appendChild(tip);

    dot.addEventListener('click', e => { e.stopPropagation(); seekToNoteTime(note.videoSec); });
    layer.appendChild(dot);
  });
}

// Wrap renderNotesList (defined in notes.js) to also refresh the markers.
// Assignment, not a function declaration, to avoid a hoisting collision.
const _origRenderNotesList = renderNotesList;
renderNotesList = function() {
  _origRenderNotesList();
  setTimeout(renderNoteMarkers, 100);
};

function _hookNoteMarkersOnLoad() {
  if (videoEl) {
    videoEl.addEventListener('loadedmetadata', renderNoteMarkers);
    videoEl.addEventListener('durationchange', renderNoteMarkers);
  }
}

// ══════════════════════════════════════════════════════════════════════════════
// FEATURE: TRANSLATE NOTES  (Claude API in-page)
// ══════════════════════════════════════════════════════════════════════════════
// Language code -> display name, shared with the PDF export (notes.php)
// which labels each saved translation by name rather than raw code.
const TRANSLATE_LANG_NAMES = {
  hi:'Hindi', te:'Telugu', ta:'Tamil', es:'Spanish', fr:'French',
  de:'German', ja:'Japanese', 'zh-CN':'Chinese', ar:'Arabic', pt:'Portuguese'
};

// Which notes are checked in the picker, kept across re-renders (e.g.
// switching target language) so the selection isn't lost.
let _translateSelected = new Set();

function initTranslate() {
  const btn      = $('translate-btn');
  const panel    = $('translate-panel');
  const closeBtn = $('translate-close-btn');
  const allBtn   = $('translate-all-btn');
  const langSel  = $('translate-lang');
  if (!btn || !panel) return;

  btn.addEventListener('click', () => {
    const isOpen = panel.classList.contains('open');
    panel.classList.toggle('open', !isOpen);
    btn.classList.toggle('active', !isOpen);
    if (!isOpen) renderTranslatePicker();
  });

  closeBtn.addEventListener('click', () => {
    panel.classList.remove('open');
    btn.classList.remove('active');
  });

  langSel.addEventListener('change', renderTranslatePicker);
  allBtn.addEventListener('click', () => translateSelectedNotes(langSel.value));
}

// Step 1: let the user pick which notes to translate, showing any
// already-saved translation for the currently chosen language.
function renderTranslatePicker() {
  const body   = $('translate-body');
  const allBtn = $('translate-all-btn');
  const langSel = $('translate-lang');
  if (!body || !langSel) return;
  const langCode = langSel.value;

  const notesWithText = _notesCache.filter(n => n.text && n.text.trim());
  if (!notesWithText.length) {
    body.innerHTML = '<div style="font-size:11px;color:var(--text-dim);padding:6px 0;">No notes with text to translate.</div>';
    if (allBtn) { allBtn.disabled = true; allBtn.textContent = 'Translate'; }
    return;
  }

  // Drop selections for notes that no longer exist.
  const validIds = new Set(notesWithText.map(n => n.id));
  _translateSelected.forEach(id => { if (!validIds.has(id)) _translateSelected.delete(id); });

  body.innerHTML = '';
  notesWithText.forEach(note => {
    const saved = note.translations && note.translations[langCode];
    const row = document.createElement('label');
    row.className = 'translate-note-row tr-pick-row';
    row.innerHTML = `
      <input type="checkbox" class="tr-check" ${_translateSelected.has(note.id) ? 'checked' : ''}>
      <div class="tr-pick-body">
        <div class="translate-note-ts">${note.ts} · ${note.type}</div>
        <div class="translate-orig">${escHtml(note.text)}</div>
        ${saved ? `<div class="translate-result tr-saved-tag">✓ ${TRANSLATE_LANG_NAMES[langCode] || langCode} saved: ${escHtml(saved)}</div>` : ''}
      </div>`;
    row.querySelector('.tr-check').addEventListener('change', e => {
      if (e.target.checked) _translateSelected.add(note.id); else _translateSelected.delete(note.id);
      _updateTranslateBtn();
    });
    body.appendChild(row);
  });

  _updateTranslateBtn();
}

function _updateTranslateBtn() {
  const allBtn = $('translate-all-btn');
  if (!allBtn) return;
  const n = _translateSelected.size;
  allBtn.textContent = n ? `Translate (${n})` : 'Translate';
  allBtn.disabled = n === 0;
}

// Google's unofficial no-key endpoint is the primary translator — verified
// correct across multiple languages including Hindi/Telugu/Tamil. MyMemory
// is free and CORS-enabled too, but it's a *translation memory* (fuzzy
// lookup against a phrase database), not real MT: for language pairs with
// sparse community data it returns an unrelated "closest match" phrase with
// a misleadingly high confidence score instead of failing — confirmed live,
// e.g. "code will execute faster" → en|hi came back as an unrelated
// sentence about waiting two hours, at reported match=0.98. So MyMemory is
// kept only as a fallback, and even then its result is discarded unless its
// own match score clears a high bar.
async function _translateViaGoogle(text, targetLangCode) {
  const url = `https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl=${targetLangCode}&dt=t&q=${encodeURIComponent(text)}`;
  const resp = await fetch(url);
  if (!resp.ok) throw new Error('Google HTTP ' + resp.status);
  const data = await resp.json();
  const segments = data && data[0];
  if (!Array.isArray(segments) || !segments.length) throw new Error('Google: no translation');
  return segments.map(seg => seg[0]).join('');
}

async function _translateViaMyMemory(text, targetLangCode) {
  const url = `https://api.mymemory.translated.net/get?q=${encodeURIComponent(text)}&langpair=en|${targetLangCode}`;
  const resp = await fetch(url);
  if (!resp.ok) throw new Error('MyMemory HTTP ' + resp.status);
  const data = await resp.json();
  const translated = data && data.responseData && data.responseData.translatedText;
  if (!translated || data.responseStatus >= 400) throw new Error('MyMemory: no translation');
  // match is MyMemory's own confidence score (0..1); below ~0.95 it's
  // frequently an unrelated fuzzy match from its phrase database, not a
  // real translation of this text — treat that as a failure too.
  if (typeof data.responseData.match === 'number' && data.responseData.match < 0.95) {
    throw new Error('MyMemory: low-confidence match (' + data.responseData.match + ')');
  }
  return translated;
}

async function _translateText(text, targetLangCode) {
  try {
    return await _translateViaGoogle(text, targetLangCode);
  } catch (_) {
    return await _translateViaMyMemory(text, targetLangCode);
  }
}

// Step 2: run translation on exactly the checked notes, then let the user
// accept (save into the note, persisted via saveNotes() same as any other
// note edit — DB when a lecture_id is present, localStorage otherwise) or
// reject (discard, leaving the note untouched) each result individually.
async function translateSelectedNotes(targetLangCode) {
  const body   = $('translate-body');
  const allBtn = $('translate-all-btn');
  if (!body) return;

  const ids = [..._translateSelected];
  const notes = ids.map(id => _notesCache.find(n => n.id === id)).filter(Boolean);
  if (!notes.length) return;

  allBtn.disabled = true;
  const prevLabel = allBtn.textContent;
  allBtn.textContent = '…';

  body.innerHTML = '';
  const rows = {};
  notes.forEach(note => {
    const row = document.createElement('div');
    row.className = 'translate-note-row';
    row.innerHTML = `
      <div class="translate-note-ts">${note.ts} · ${note.type}</div>
      <div class="translate-orig">${escHtml(note.text)}</div>
      <div class="translate-result translate-spinner">⏳ translating…</div>
      <div class="tr-actions hidden"></div>`;
    rows[note.id] = row;
    body.appendChild(row);
  });

  // Each note is its own request (neither free endpoint batches), run in
  // parallel; one note's failure doesn't block the others.
  await Promise.all(notes.map(async note => {
    const row = rows[note.id];
    const resEl = row && row.querySelector('.translate-result');
    const actionsEl = row && row.querySelector('.tr-actions');
    if (!resEl) return;
    try {
      const translated = await _translateText(note.text, targetLangCode);
      resEl.classList.remove('translate-spinner');
      resEl.textContent = translated || '—';
      if (actionsEl) {
        actionsEl.classList.remove('hidden');
        actionsEl.innerHTML = `
          <button class="tr-accept-btn" type="button">✓ Correct — save</button>
          <button class="tr-reject-btn" type="button">✗ Discard</button>`;
        actionsEl.querySelector('.tr-accept-btn').addEventListener('click', () => {
          note.translations = note.translations || {};
          note.translations[targetLangCode] = translated;
          saveNotes();
          actionsEl.innerHTML = `<span class="tr-saved-flag">✓ Saved — included in PDF export</span>`;
        });
        actionsEl.querySelector('.tr-reject-btn').addEventListener('click', () => {
          row.remove();
        });
      }
    } catch (err) {
      resEl.classList.remove('translate-spinner');
      resEl.style.color = '#f87171';
      resEl.textContent = 'Translation failed — ' + err.message;
    }
  }));

  allBtn.disabled = false;
  allBtn.textContent = prevLabel;
}
