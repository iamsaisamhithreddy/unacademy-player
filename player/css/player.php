<?php header("Content-Type: text/css"); ?>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg: #0d0d0f;
  --surface: #16161a;
  --surface2: #1e1e24;
  --border: #2e2e38;
  --text: #e8e8f0;
  --text-dim: #888899;
  --hi: #a78bfa;
  --hi2: #7c3aed;
  --bar-h: 54px;
  --radius: 8px;
}

body {
  background: var(--bg);
  color: var(--text);
  font-family: system-ui, -apple-system, sans-serif;
  height: 100dvh;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.hidden { display: none !important; }

/* ── Loading ── */
#loading-overlay {
  position: fixed; inset: 0; z-index: 1000;
  background: var(--bg);
  display: flex; flex-direction: column;
  align-items: center; justify-content: center; gap: 16px;
}
#loading-overlay .spinner {
  width: 42px; height: 42px;
  border: 3px solid var(--border);
  border-top-color: var(--hi);
  border-radius: 50%;
  animation: spin .8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
#loading-status { color: var(--text-dim); font-size: 13px; }

/* ── Setup ── */
#setup-section {
  flex: 1; display: flex; align-items: center; justify-content: center;
}
.setup-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 16px; padding: 44px 52px;
  text-align: center; max-width: 520px; width: 92%;
}
.setup-card h1 { font-size: 24px; font-weight: 700; color: var(--hi); margin-bottom: 6px; }
.setup-card p  { color: var(--text-dim); font-size: 13px; margin-bottom: 20px; }

/* Tabs */
.tab-bar { display: flex; gap: 0; margin-bottom: 20px; border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border); }
.tab-btn {
  flex: 1; padding: 9px 0; font-size: 13px; font-weight: 600;
  background: var(--surface2); color: var(--text-dim); border: none; cursor: pointer;
  transition: background .15s, color .15s;
}
.tab-btn.active { background: var(--hi); color: #fff; }
.tab-pane { display: none; flex-direction: column; gap: 10px; }
.tab-pane.active { display: flex; }

.setup-fields { display: flex; flex-direction: column; gap: 10px; }
.setup-fields input[type=text], .setup-fields input[type=url] {
  background: var(--surface2); border: 1px solid var(--border);
  color: var(--text); padding: 10px 14px; border-radius: var(--radius);
  font-size: 13px; outline: none; width: 100%;
  transition: border-color .15s;
}
.setup-fields input[type=text]:focus,
.setup-fields input[type=url]:focus { border-color: var(--hi); }
.setup-fields button, .tab-load-btn {
  background: var(--hi); color: #fff; border: none;
  padding: 11px; border-radius: var(--radius);
  font-size: 14px; font-weight: 600; cursor: pointer;
  transition: background .15s; width: 100%;
}
.setup-fields button:hover, .tab-load-btn:hover { background: var(--hi2); }

/* File upload drop zones */
.file-drop {
  border: 2px dashed var(--border); border-radius: var(--radius);
  padding: 14px 12px; text-align: center; cursor: pointer;
  transition: border-color .15s, background .15s;
  position: relative;
}
.file-drop:hover, .file-drop.drag-over { border-color: var(--hi); background: rgba(167,139,250,.06); }
.file-drop input[type=file] {
  position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.file-drop .fd-icon { font-size: 20px; margin-bottom: 4px; }
.file-drop .fd-label { font-size: 12px; font-weight: 600; color: var(--text); }
.file-drop .fd-sub   { font-size: 11px; color: var(--text-dim); margin-top: 2px; }
.file-drop.has-file  { border-color: var(--hi); border-style: solid; }
.file-drop.has-file .fd-label { color: var(--hi); }

.hint { font-size: 11px; color: var(--text-dim); margin-top: 8px; opacity: .7; text-align: center; }

/* ── Player ── */
#player-section {
  flex: 1; display: flex; flex-direction: column; overflow: hidden; position: relative;
}
#player-body {
  flex: 1; display: flex; overflow: hidden; position: relative;
}

/* ── Slide panel ── */
#slide-panel {
  flex: 1; background: #111; position: relative; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
}
/* Enforce 16:9 — annotations are normalised against a 16:9 board, so the
   container must actually BE 16:9. Do not re-add height:100% here: with both
   width and height definite, browsers ignore aspect-ratio. */
#slide-container {
  position: relative;
  width: 100%;
  max-width: calc(100vh * 16 / 9 * 0.92);
  aspect-ratio: 16 / 9;
  max-height: 100%;
}
#slide-container canvas { position: absolute; inset: 0; width: 100%; height: 100%; }
#slide-canvas { z-index: 1; }
#draw-canvas  { z-index: 2; pointer-events: none; }

/* ── Screen-share mode: instructor is sharing their screen, so the recorded
   video IS the content — the webcam video element gets moved in here (see
   setScreenShareMode() in player.js) and shown full-size above the whiteboard
   annotation canvases, which are not meaningful during this span. ── */
#slide-container.ss-mode #webcam-video {
  position: absolute; inset: 0; width: 100%; height: 100%; aspect-ratio: unset;
  z-index: 4; object-fit: contain; background: #000;
}
#slide-container.ss-mode #slide-canvas,
#slide-container.ss-mode #draw-canvas,
#slide-container.ss-mode #user-draw-canvas { visibility: hidden; }

/* ── Right sidebar (webcam + notes) ── */
#right-sidebar {
  width: 300px; flex-shrink: 0;
  background: var(--surface);
  border-left: 1px solid var(--border);
  display: flex; flex-direction: column;
  overflow: hidden; transition: width .2s;
}
#right-sidebar.collapsed { width: 0; border: none; }
#webcam-wrap {
  flex-shrink: 0; background: #000;
  border-bottom: 1px solid var(--border);
}
.panel-label {
  font-size: 10px; font-weight: 600; letter-spacing: .08em;
  color: var(--text-dim); background: var(--surface2);
  padding: 5px 10px; text-transform: uppercase;
  border-bottom: 1px solid var(--border); user-select: none;
  display: flex; align-items: center; justify-content: space-between;
}
#webcam-video { width: 100%; aspect-ratio: 16/9; background: #000; display: block; }

/* ── Notes panel ── */
#notes-panel { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-height: 0; }
#notes-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 10px; border-bottom: 1px solid var(--border); flex-shrink: 0;
}
#notes-header span { font-size: 13px; font-weight: 600; color: var(--text); }
#add-note-btn {
  background: var(--hi); color: #fff; border: none;
  padding: 4px 10px; border-radius: 5px; font-size: 11px; font-weight: 600;
  cursor: pointer;
}
#add-note-btn:hover { background: var(--hi2); }

/* Composer */
#note-composer {
  padding: 8px 10px; border-bottom: 1px solid var(--border);
  flex-shrink: 0; display: none; flex-direction: column; gap: 6px;
}
#note-composer.open { display: flex; }
#note-time-badge { font-size: 10px; font-weight: 700; color: var(--hi); }
#note-text {
  background: var(--surface2); border: 1px solid var(--border);
  color: var(--text); border-radius: 6px; padding: 8px;
  font-size: 12px; resize: none; height: 60px; outline: none; font-family: inherit;
}
#note-text:focus { border-color: var(--hi); }
.note-type-row { display: flex; gap: 6px; flex-wrap: wrap; }
.note-type-btn {
  font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 5px;
  border: 1px solid var(--border); background: none; color: var(--text-dim); cursor: pointer;
  transition: all .12s;
}
.note-type-btn[data-type="bookmark"].sel  { background:#3b82f6; border-color:#3b82f6; color:#fff; }
.note-type-btn[data-type="important"].sel { background:#f59e0b; border-color:#f59e0b; color:#fff; }
.note-type-btn[data-type="note"].sel      { background:#10b981; border-color:#10b981; color:#fff; }
.note-composer-actions { display: flex; gap: 6px; justify-content: flex-end; }
.btn-save   { background: var(--hi); color:#fff; border:none; padding:5px 14px; border-radius:5px; font-size:12px; font-weight:600; cursor:pointer; }
.btn-cancel { background:none; color:var(--text-dim); border:1px solid var(--border); padding:5px 10px; border-radius:5px; font-size:12px; cursor:pointer; }
.btn-save:hover { background:var(--hi2); }

/* List */
#notes-list { flex:1; overflow-y:auto; padding: 4px 0; }
#notes-empty { text-align:center; color:var(--text-dim); font-size:12px; padding:32px 12px; line-height:1.7; }
.note-item {
  padding: 8px 10px; border-bottom: 1px solid rgba(255,255,255,.04);
  cursor: pointer; transition: background .1s;
  position: relative; display: flex; flex-direction: column; gap: 3px;
}
.note-item:hover { background: var(--surface2); }
.note-item-top { display: flex; align-items: center; gap: 6px; }
.note-ts {
  font-size: 11px; font-weight: 700; padding: 1px 6px;
  border-radius: 10px; white-space: nowrap;
}
.note-ts.bookmark  { background:rgba(59,130,246,.2);  color:#60a5fa; }
.note-ts.important { background:rgba(245,158,11,.2);  color:#fbbf24; }
.note-ts.note      { background:rgba(16,185,129,.2);  color:#34d399; }
.note-tag { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--text-dim); }
.note-body { font-size:12px; color:var(--text); line-height:1.45; white-space:pre-wrap; word-break:break-word; }
.note-del {
  position:absolute; right:6px; top:50%; transform:translateY(-50%);
  background:none; border:none; color:#555; cursor:pointer;
  font-size:14px; padding:4px 6px; border-radius:4px; opacity:0;
}
.note-item:hover .note-del { opacity:1; }
.note-del:hover { color:#f87171; }

/* ── Notes action buttons (Import / Export) ── */
.notes-action-btn {
  background: var(--surface2); color: var(--text-dim);
  border: 1px solid var(--border); border-radius: 5px;
  padding: 3px 8px; font-size: 12px; cursor: pointer;
  transition: all .12s;
}
.notes-action-btn:hover { color: var(--text); border-color: var(--hi); }

/* ── Modal overlay ── */
#notes-modal {
  position: absolute; inset: 0; z-index: 100;
  background: rgba(0,0,0,.7);
  display: flex; align-items: center; justify-content: center;
  backdrop-filter: blur(3px);
}
#notes-modal-box {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 12px; width: 90%; max-width: 280px;
  box-shadow: 0 16px 48px rgba(0,0,0,.6);
  overflow: hidden;
}
.modal-title {
  font-size: 14px; font-weight: 700; color: var(--text);
  padding: 14px 14px 10px; border-bottom: 1px solid var(--border);
}
.modal-body { padding: 10px 14px; display: flex; flex-direction: column; gap: 8px; }
.modal-footer {
  padding: 8px 14px; border-top: 1px solid var(--border);
  display: flex; justify-content: flex-end;
}
.modal-close-btn {
  background: none; color: var(--text-dim); border: 1px solid var(--border);
  padding: 5px 14px; border-radius: 5px; font-size: 12px; cursor: pointer;
}
.modal-close-btn:hover { color: var(--text); }

/* Export big buttons */
.modal-big-btn {
  display: flex; align-items: center; gap: 12px;
  background: var(--surface2); border: 1px solid var(--border);
  border-radius: 8px; padding: 12px; cursor: pointer; text-align: left;
  color: var(--text); font-size: 12px; width: 100%;
  transition: border-color .12s, background .12s;
}
.modal-big-btn:hover { border-color: var(--hi); background: rgba(167,139,250,.06); }
.mbtn-icon { font-size: 24px; flex-shrink: 0; }
.mbtn-sub  { font-size: 11px; color: var(--text-dim); margin-top: 2px; }

/* PDF progress */
#pdf-progress { padding: 8px 14px; }
#pdf-progress-bar {
  height: 4px; background: var(--border); border-radius: 2px; overflow: hidden; margin-bottom: 6px;
}
#pdf-progress-fill {
  height: 100%; background: var(--hi); border-radius: 2px;
  width: 0%; transition: width .3s;
}
#pdf-progress-label { font-size: 11px; color: var(--text-dim); }

/* Import drop zone */
.import-drop {
  border: 2px dashed var(--border); border-radius: 8px;
  padding: 20px 12px; text-align: center; cursor: pointer;
  position: relative; transition: border-color .15s;
}
.import-drop:hover, .import-drop.drag-over { border-color: var(--hi); }
.import-drop input[type=file] {
  position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
#import-preview { margin-top: 8px; }
.import-mode-row { display: flex; flex-direction: column; gap: 6px; }
.import-mode-opt {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; color: var(--text-dim); cursor: pointer;
}
.import-mode-opt input { cursor: pointer; accent-color: var(--hi); }

/* FIXED: this rule had lost its selector in the original file */
#sidebar-toggle-btn {
  background: none; border: none; color: var(--text-dim); cursor: pointer;
  padding: 2px 4px; border-radius: 4px; font-size: 14px; line-height: 1;
}
#sidebar-toggle-btn:hover { color: var(--text); }

/* ── Buffering ── */
#buffering-overlay {
  position: absolute; inset: 0; z-index: 15;
  background: rgba(0,0,0,.45);
  display: flex; align-items: center; justify-content: center;
}
.svg-spinner { width: 44px; height: 44px; }
.svg-spinner circle {
  fill: none; stroke: var(--hi); stroke-width: 4;
  stroke-dasharray: 80; stroke-linecap: round;
  animation: spin 1s linear infinite;
}

/* ── Gesture feedback ── */
#gesture-feedback {
  position: absolute; inset: 0; z-index: 30;
  display: flex; align-items: center; justify-content: center;
  pointer-events: none; color: #fff;
  font-size: 22px; font-weight: 700;
  text-shadow: 0 2px 8px rgba(0,0,0,.7);
  opacity: 0;
}
#gesture-feedback.show { animation: gfade 1s ease forwards; }
@keyframes gfade {
  0% { opacity: 1; transform: scale(1); }
  60% { opacity: 1; }
  100% { opacity: 0; transform: scale(1.25); }
}

/* ── Stats ── */
#stats-bar {
  position: absolute; top: 8px; left: 50%; transform: translateX(-50%);
  background: rgba(0,0,0,.55); color: var(--text-dim);
  font-size: 11px; padding: 3px 12px; border-radius: 20px;
  pointer-events: none; z-index: 5; white-space: nowrap;
}

/* ── Controls ── */
#controls-bar {
  height: var(--bar-h); background: var(--surface);
  border-top: 1px solid var(--border);
  display: flex; align-items: center;
  padding: 0 10px; gap: 6px;
  flex-shrink: 0; z-index: 50;
  transition: opacity .3s;
}
#player-section.idle #controls-bar { opacity: 0; pointer-events: none; }
#player-section:not(.idle) #controls-bar { opacity: 1; }
/* Color toolbar always stays visible */
#color-toolbar { transition: none; }

.ctrl-btn {
  background: none; border: none; color: var(--text-dim);
  cursor: pointer; padding: 6px; border-radius: 6px;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  transition: color .15s, background .15s;
}
.ctrl-btn:hover { color: var(--text); background: var(--surface2); }
.ctrl-btn.active { color: var(--hi); background: var(--surface2); box-shadow: inset 0 0 0 1px var(--hi); }
.ctrl-btn svg { width: 18px; height: 18px; pointer-events: none; }

/* ── Seek ── */
#seek-bar-container {
  flex: 1; position: relative; height: 20px; display: flex; align-items: center;
}
#buffer-bar {
  position: absolute; left: 0; top: 50%; transform: translateY(-50%);
  height: 3px; background: rgba(255,255,255,.18); border-radius: 2px;
  pointer-events: none; width: 0; transition: width .3s;
}
#seek-bar {
  -webkit-appearance: none; appearance: none;
  width: 100%; height: 4px; border-radius: 2px;
  outline: none; cursor: pointer; background: var(--border); position: relative; z-index: 1;
}
#seek-bar::-webkit-slider-thumb {
  -webkit-appearance: none; width: 13px; height: 13px; border-radius: 50%;
  background: var(--hi); cursor: pointer;
  box-shadow: 0 0 6px rgba(167,139,250,.5);
  transition: transform .1s;
}
#seek-bar:hover::-webkit-slider-thumb { transform: scale(1.3); }
#seek-hover-dot {
  position: absolute; top: 50%; transform: translateY(-50%) translateX(-50%);
  width: 10px; height: 10px; border-radius: 50%;
  background: var(--hi); pointer-events: none; z-index: 2;
}
#seek-tooltip {
  position: absolute; bottom: 26px; transform: translateX(-50%);
  background: var(--surface2); border: 1px solid var(--border);
  padding: 3px 8px; border-radius: 5px; pointer-events: none; z-index: 3;
}
#seek-tooltip-time { font-size: 11px; color: var(--text); font-variant-numeric: tabular-nums; }

/* ── Volume ── */
#volume-slider-bar {
  -webkit-appearance: none; appearance: none;
  width: 68px; height: 3px; border-radius: 2px;
  outline: none; cursor: pointer; background: var(--border); flex-shrink: 0;
}
#volume-slider-bar::-webkit-slider-thumb {
  -webkit-appearance: none; width: 12px; height: 12px;
  border-radius: 50%; background: var(--text); cursor: pointer;
}

/* ── Time ── */
#time-display {
  font-size: 12px; color: var(--text-dim); white-space: nowrap;
  flex-shrink: 0; font-variant-numeric: tabular-nums;
}

/* ── Settings ── */
#settings-container { position: relative; }
#settings-popup {
  position: absolute; bottom: calc(100% + 10px); right: 0;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 12px; min-width: 180px;
  z-index: 100; box-shadow: 0 8px 24px rgba(0,0,0,.5);
}
.settings-item {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; padding: 6px 0; font-size: 13px; color: var(--text-dim);
}
.settings-item:not(:last-child) { border-bottom: 1px solid var(--border); }
.settings-select {
  background: var(--surface2); color: var(--text);
  border: 1px solid var(--border); border-radius: 5px;
  padding: 4px 8px; font-size: 12px; cursor: pointer;
}

/* ── Color Toolbar ── */
#color-toolbar {
  display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
  padding: 5px 10px;
  background: var(--surface2);
  border-top: 1px solid var(--border);
  flex-shrink: 0;
  min-height: 38px;
}
.ct-group { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }
.ct-label {
  font-size: 9px; font-weight: 700; letter-spacing: .08em;
  color: var(--text-dim); text-transform: uppercase; white-space: nowrap;
}
.ct-sep { width: 1px; height: 22px; background: var(--border); flex-shrink: 0; }
.bg-swatch, .pen-swatch {
  width: 22px; height: 22px; border-radius: 4px;
  border: 2px solid #555; cursor: pointer; padding: 0;
  flex-shrink: 0; transition: transform .1s, border-color .1s;
}
.bg-swatch:hover, .pen-swatch:hover { transform: scale(1.15); }
.bg-swatch.sel, .pen-swatch.sel {
  border-color: #a78bfa !important;
  box-shadow: 0 0 6px rgba(167,139,250,.6);
  transform: scale(1.18);
}
.pen-swatch { border-radius: 50%; }
#bg-custom, #pen-custom {
  width: 28px; height: 22px; border: 2px solid #555;
  border-radius: 4px; cursor: pointer; padding: 0;
  background: #050505; flex-shrink: 0;
}
.ct-mode-btn {
  font-size: 9px; font-weight: 700; letter-spacing: .05em;
  background: #00d0ff; color: #000; border: none;
  padding: 3px 8px; border-radius: 4px; cursor: pointer;
  white-space: nowrap;
}
.ct-mode-btn.custom { background: #ffea00; }
.ct-warn { font-size: 10px; color: #ff5555; }

/* ── Mobile ── */
@media (max-width: 600px) {
  #webcam-panel { width: 130px; }
  #volume-slider-bar { width: 48px; }
  #time-display { display: none; }
  #color-toolbar { gap: 4px; padding: 4px 8px; }
  .bg-swatch, .pen-swatch { width: 18px; height: 18px; }
}

/* ── User Draw Canvas ── */
#user-draw-canvas {
  position: absolute; inset: 0; width: 100%; height: 100%;
  z-index: 10; cursor: crosshair;
  pointer-events: none;
}
#user-draw-canvas.active { pointer-events: all; }

/* ── Drawing Toolbar ── */
#draw-toolbar {
  position: absolute; left: 10px; top: 10px; z-index: 40;
  display: flex; flex-direction: column; gap: 6px;
  background: rgba(13,13,15,0.88); border: 1px solid var(--border);
  border-radius: 10px; padding: 8px 6px;
  backdrop-filter: blur(6px);
  transition: opacity .2s;
  opacity: 0; pointer-events: none;
}
#draw-toolbar.visible { opacity: 1; pointer-events: all; }
.dtb-btn {
  width: 32px; height: 32px; border-radius: 6px;
  border: 1px solid var(--border); background: var(--surface2);
  color: var(--text-dim); cursor: pointer; font-size: 14px;
  display: flex; align-items: center; justify-content: center;
  transition: all .12s; flex-shrink: 0;
}
.dtb-btn:hover { color: var(--text); border-color: var(--hi); }
.dtb-btn.active { background: var(--hi); border-color: var(--hi); color: #fff; }
.dtb-sep { height: 1px; background: var(--border); margin: 2px 0; }
#dtb-color { width: 32px; height: 28px; border: 2px solid #555; border-radius: 5px; cursor: pointer; padding: 0; }
#dtb-size {
  width: 32px; height: 80px;
  -webkit-appearance: slider-vertical; appearance: slider-vertical;
  accent-color: var(--hi);
}

/* ── Off-board scratch content strip ──
   Opt-in view (toggled via #offboard-toggle-btn) that surfaces annotation
   groups whose coordinates fall outside the visible 16:9 board — e.g.
   pasted/duplicated copies the instructor dragged off-screen as scratch
   templates. These are never part of the normal render; this strip makes
   them inspectable without claiming they were ever shown on the board. */
#offboard-strip {
  position: absolute; left: 0; right: 0; bottom: 0; z-index: 35;
  background: rgba(13,13,15,0.92); border-top: 1px solid var(--border);
  backdrop-filter: blur(6px);
  max-height: 34%;
  display: flex; flex-direction: column;
}
#offboard-strip-label {
  font-size: 10px; letter-spacing: .06em; color: var(--text-dim);
  padding: 6px 10px 4px;
}
#offboard-strip-track {
  display: flex; gap: 10px; overflow-x: auto; padding: 0 10px 10px;
}
.offboard-card {
  flex-shrink: 0; width: 150px;
  background: var(--surface2); border: 1px solid var(--border); border-radius: 8px;
  overflow: hidden;
}
.offboard-card canvas { display: block; width: 100%; height: 90px; background: #202022; }
.offboard-card .oc-label {
  font-size: 10.5px; color: var(--text); padding: 4px 7px;
  border-top: 1px solid var(--border);
  display: flex; justify-content: space-between; gap: 6px;
}
.offboard-card .oc-label .oc-idx { color: var(--text-dim); }
.offboard-card.oc-empty .oc-label { color: var(--text-dim); }
#offboard-strip-track:empty::after {
  content: "No off-board content on this board.";
  color: var(--text-dim); font-size: 12px; padding: 8px 2px;
}

/* ── Chapter / Slide Navigator ── */
#chapter-panel {
  flex: 1; display: flex; flex-direction: column; overflow: hidden; min-height: 0;
}
.chapter-item {
  display: flex; gap: 8px; align-items: flex-start;
  padding: 7px 8px; border-bottom: 1px solid rgba(255,255,255,.04);
  cursor: pointer; transition: background .1s;
}
.chapter-item:hover { background: var(--surface2); }
.chapter-item.active { background: rgba(167,139,250,.12); border-left: 2px solid var(--hi); }
.chapter-thumb {
  width: 72px; height: 40px; border-radius: 4px;
  background: #222; flex-shrink: 0; overflow: hidden; position: relative;
}
.chapter-thumb canvas { width: 100%; height: 100%; display: block; }
.chapter-thumb .ct-placeholder {
  width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
  font-size: 18px; background: var(--surface2);
}
.chapter-info { flex: 1; min-width: 0; }
.chapter-ts { font-size: 10px; font-weight: 700; color: var(--hi); }
.chapter-title { font-size: 11px; color: var(--text); margin-top: 2px; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chapter-sub { font-size: 10px; color: var(--text-dim); margin-top: 1px; }
#chapter-list { flex: 1; overflow-y: auto; }
#chapter-empty { text-align: center; color: var(--text-dim); font-size: 12px; padding: 28px 12px; line-height: 1.7; }

/* ── Sidebar tabs (Notes | Chapters) ── */
#sidebar-tabs { display: flex; border-bottom: 1px solid var(--border); flex-shrink: 0; }
.stab-btn {
  flex: 1; padding: 7px 4px; font-size: 11px; font-weight: 600;
  background: none; border: none; color: var(--text-dim); cursor: pointer;
  border-bottom: 2px solid transparent; transition: all .12s;
}
.stab-btn.active { color: var(--hi); border-bottom-color: var(--hi); }
.stab-pane { display: none; flex: 1; overflow: hidden; flex-direction: column; min-height: 0; }
.stab-pane.active { display: flex; }

/* ── Highlight Reel + Multi-sync — controls in notes header ── */
.notes-extra-btn {
  background: var(--surface2); color: var(--text-dim);
  border: 1px solid var(--border); border-radius: 5px;
  padding: 3px 7px; font-size: 11px; cursor: pointer;
  transition: all .12s; white-space: nowrap;
}
.notes-extra-btn:hover { color: var(--text); border-color: var(--hi); }
.notes-extra-btn.active { background: rgba(167,139,250,.2); border-color: var(--hi); color: var(--hi); }

/* Highlight reel selection mode */
.note-item.selectable { user-select: none; }
.note-item.selectable::before {
  content: ''; display: inline-block; width: 14px; height: 14px;
  border: 2px solid var(--border); border-radius: 3px; margin-right: 6px;
  vertical-align: middle; flex-shrink: 0; transition: all .1s;
}
.note-item.sel-mode::before { content: '☐'; border: none; width: auto; height: auto; font-size: 14px; }
.note-item.checked::before { content: '☑'; color: var(--hi); border: none; width: auto; height: auto; font-size: 14px; }

/* Sync badge */
#sync-badge {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10px; padding: 2px 7px; border-radius: 10px;
  background: rgba(16,185,129,.15); color: #34d399;
  border: 1px solid rgba(16,185,129,.3);
}
#sync-badge.offline { background: rgba(136,136,153,.1); color: var(--text-dim); border-color: var(--border); }

/* ── Highlight reel modal ── */
#reel-modal {
  position: fixed; inset: 0; z-index: 200;
  background: rgba(0,0,0,.7); display: flex; align-items: center; justify-content: center;
  backdrop-filter: blur(4px);
}
#reel-modal-box {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 12px; width: 92%; max-width: 360px;
  box-shadow: 0 20px 60px rgba(0,0,0,.6); overflow: hidden;
}
#reel-progress-bar { height: 4px; background: var(--border); }
#reel-progress-fill { height: 100%; background: var(--hi); width: 0%; transition: width .3s; }

/* ── Seek thumbnail preview ── */
#seek-thumbnail-preview {
  position: absolute;
  bottom: 32px;
  transform: translateX(-50%);
  pointer-events: none;
  z-index: 10;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 3px;
  opacity: 0;
  transition: opacity .12s;
}
#seek-thumbnail-preview.show { opacity: 1; }
#seek-thumb-canvas {
  width: 160px;
  height: 90px;
  border-radius: 5px;
  border: 1px solid var(--border);
  background: #111;
  display: block;
}
#seek-thumb-time {
  font-size: 11px;
  font-weight: 700;
  color: var(--text);
  background: rgba(0,0,0,.75);
  padding: 2px 7px;
  border-radius: 4px;
}

/* ── Note markers on seek bar ── */
#note-markers-layer {
  position: absolute;
  left: 0; top: 0;
  width: 100%; height: 100%;
  pointer-events: none;
  z-index: 2;
}
.note-marker {
  position: absolute;
  top: 50%;
  transform: translate(-50%, -50%);
  width: 8px; height: 8px;
  border-radius: 50%;
  border: 1.5px solid rgba(0,0,0,.4);
  cursor: pointer;
  pointer-events: all;
  transition: transform .1s;
  z-index: 3;
}
.note-marker:hover { transform: translate(-50%, -50%) scale(1.6); }
.note-marker.bookmark  { background: #60a5fa; }
.note-marker.important { background: #fbbf24; }
.note-marker.note      { background: #34d399; }
.note-marker-tooltip {
  position: absolute;
  bottom: 14px;
  left: 50%;
  transform: translateX(-50%);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 5px;
  padding: 3px 7px;
  font-size: 10px;
  color: var(--text);
  white-space: nowrap;
  max-width: 160px;
  overflow: hidden;
  text-overflow: ellipsis;
  pointer-events: none;
  opacity: 0;
  transition: opacity .1s;
}
.note-marker:hover .note-marker-tooltip { opacity: 1; }

/* ── Translate panel ── */
#translate-panel {
  border-top: 1px solid var(--border);
  flex-shrink: 0;
  background: var(--surface2);
  display: none;
  flex-direction: column;
  max-height: 220px;
  overflow: hidden;
}
#translate-panel.open { display: flex; }
#translate-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 6px 10px; border-bottom: 1px solid var(--border); flex-shrink: 0;
}
#translate-header span { font-size: 11px; font-weight: 700; color: var(--text-dim); }
#translate-header-controls { display: flex; gap: 5px; align-items: center; }
#translate-lang {
  background: var(--surface); color: var(--text);
  border: 1px solid var(--border); border-radius: 5px;
  padding: 3px 6px; font-size: 11px; cursor: pointer;
}
#translate-close-btn {
  background: none; border: none; color: var(--text-dim);
  cursor: pointer; font-size: 14px; padding: 0 2px; line-height: 1;
}
#translate-close-btn:hover { color: var(--text); }
#translate-body { flex: 1; overflow-y: auto; padding: 8px 10px; display: flex; flex-direction: column; gap: 6px; }
.translate-note-row {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 7px 9px;
  font-size: 11px;
}
.translate-note-ts { font-size: 10px; font-weight: 700; color: var(--hi); margin-bottom: 3px; }
.translate-orig { color: var(--text-dim); margin-bottom: 4px; font-style: italic; }
.translate-result { color: var(--text); line-height: 1.45; }
.translate-spinner { color: var(--text-dim); font-size: 11px; }

/* Selection picker (step 1: choose which notes to translate) */
.tr-pick-row { display: flex; align-items: flex-start; gap: 8px; cursor: pointer; }
.tr-pick-row .tr-check { margin-top: 2px; flex-shrink: 0; accent-color: var(--hi); cursor: pointer; }
.tr-pick-body { flex: 1; min-width: 0; }
.tr-saved-tag { color: #34d399; font-size: 10.5px; margin-top: 2px; }

/* Accept / reject controls (step 2: after a translation comes back) */
.tr-actions { display: flex; gap: 6px; margin-top: 6px; }
.tr-actions.hidden { display: none; }
.tr-accept-btn, .tr-reject-btn {
  border: 1px solid var(--border); border-radius: 5px; background: var(--surface2);
  color: var(--text-dim); font-size: 10.5px; padding: 3px 8px; cursor: pointer;
}
.tr-accept-btn:hover { border-color: #34d399; color: #34d399; }
.tr-reject-btn:hover { border-color: #f87171; color: #f87171; }
.tr-saved-flag { color: #34d399; font-size: 11px; }
#translate-all-btn {
  background: var(--hi); color: #fff; border: none;
  padding: 3px 10px; border-radius: 5px; font-size: 11px; font-weight: 600; cursor: pointer;
}
#translate-all-btn:hover { background: var(--hi2); }
#translate-all-btn:disabled { opacity: .5; cursor: default; }