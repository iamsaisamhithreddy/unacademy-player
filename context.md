# UA Lecture Player — Full Context Document

## What This Is

A single self-contained `player.html` file (~110KB) that replicates the Unacademy lesson player experience. It plays `.webm` lecture videos in sync with instructor annotations (drawing strokes, slide changes, pointer movements) replayed from a `data.json` event log. Works both online (via URLs) and fully offline (via local file upload). Has a notes/bookmarks system with export to JSON and annotated PDF.

---

## Files Involved

| File | Purpose |
|---|---|
| `player.html` | The entire player — HTML + CSS + all JS inlined, single file, no build step |
| `output.webm` | The lecture video (instructor webcam feed) |
| `data.json` | Event log from UA's servers — slide changes, strokes, pointer movements, tool switches |
| `*.pdf` (optional) | Slide PDF — used as fallback background if no per-slide image URLs exist in data.json |

The player was built from these original UA source files you uploaded:
- `t3k1.js` → `TimelineEngine` class (verbatim)
- `s7m2.js` → `StateManager` class (verbatim)
- `p4r3.js` → `PDFRenderer` class (verbatim + extended)
- `c9r4.js` → `CanvasRenderer` class (verbatim)
- `pk05.js` → `PlayerController` class (this file was blocked/missing, so reconstructed from usage patterns in `a8f2.js`)

---

## URL Patterns

### Online mode (tradeedify.in style)
```
player.html?video=https://uamedia.uacdn.net/lesson-raw/VIDEO_ID/output.webm&pdf=https://player.uacdn.net/slides_pdf/VIDEO_ID/NAME.pdf
```

- `video=` — required, the `.webm` video URL
- `pdf=` — optional, the slides PDF URL
- `data.json` is auto-derived by replacing `output.webm` with `data.json` in the same URL path
- `t=SECONDS` — optional, auto-seeks to that second on load (used by PDF export links)

### Offline mode
Open `player.html` directly in browser → switch to **📁 Offline** tab → drop files.

---

## Architecture Overview

```
data.json
    │
    ▼
TimelineEngine          — parses & indexes all events by timestamp
    │
    ▼
StateManager            — tracks current slide, active strokes, pen/eraser state
    │
    ├──► PDFRenderer    — draws slide background onto #slide-canvas
    └──► CanvasRenderer — draws annotation strokes onto #draw-canvas
              │
              ▼
         PlayerController  — RAF loop: video time → event time → apply events → render
```

The two canvases sit stacked in `#slide-container`:
- `#slide-canvas` (z-index 1) — slide images / PDF pages
- `#draw-canvas` (z-index 2, pointer-events:none) — instructor ink on top

---

## Engine Classes (Core Logic)

### `TimelineEngine` (from t3k1.js)

Parses `data.json` and builds a sorted event array. Handles the tricky time mapping between video playback time and event timestamps.

**Key problem it solves:** UA's `p_time` values are raw microsecond timestamps (like `1727269...`), not relative seconds. Two modes:
- If duration > 10,000,000 µs → microseconds, `divider = 1000` to convert to ms
- Otherwise → already in ms, `divider = 1`

**Key methods:**
```js
timeline.load(rawData)
// Flattens nested JSON, extracts all {plugin, p_time, data} events,
// computes minTime/maxTime/t0/eventSpanUs/divider, sorts by timeMs.

timeline.videoToEventMs(videoMs, videoDurationMs)
// Maps video.currentTime (ms) → event timeline (ms).
// Uses proportional seek: frac = (videoMs - syncLag) / videoDuration
// Then: raw = t0 + frac * eventSpanUs
// Then: (raw - minTime) / divider
// syncLagMs defaults to 2000 (2 second offset), configurable via
// localStorage.getItem("ua_sync_lag_ms")

timeline.getNewEvents(currentMs)
// Returns events since last call (forward playback only).
// Uses binary search + lastEventIndex cursor.

timeline.getAllEventsUpTo(targetMs)
// Returns ALL events from beginning to targetMs.
// Used by rebuildState() on seek.

timeline.resetCursor(targetMs)
// Repositions lastEventIndex after a seek.

timeline.binarySearch(targetMs)
// Returns index of last event <= targetMs.
```

**Lesson time origin (`t0`):** Found by scanning for the first `mcn` plugin event with `e === "cp"` (checkpoint). This is the real lesson start time. Events before this are setup/metadata.

---

### `StateManager` (from s7m2.js)

Tracks everything about the current visual state: which slide is showing, all ink strokes on every slide, current pen color/size/tool, zoom/pan, pointer position.

**Slide identity system:**
Each slide has a stable ID called `sid` (slide ID). Built from `d.uid || (slideUrl + "_" + p_time)` via `asSid()`.

The `INIT_SID = "init"` is the default state before any slide is loaded.

**Three internal maps built by `buildSlideIndex()`:**

1. `deck[]` — ordered array of all slides in presentation order. Each entry: `{url, bc, bg, sid, pg}`
2. `slideTimeline[]` — array of `{timeMs, ptr, sid, url}` built by replaying slide-change events in order. Used to answer "what slide is active at time T?"
3. `eventSidMap` — Map from each event object → which `sid` that event belongs to. Used to route strokes to the correct slide's stroke map.

**Stroke storage:**
```js
strokesBySid: Map<sid, {
  strokes: Map<strokeKey, stroke>,
  activeByPanel: Map<panelId, stroke>  // in-progress strokes
}>
```

Each stroke: `{aId, color, size, tool, timeMs, isActive, points: [{x,y}]}`
- `x, y` are normalised 0–1 values (relative to slide dimensions, not canvas pixels)
- Original UA coordinate space is 750×422 px; normalisation: `nx = rx > 1 ? rx/750 : rx`

**Event plugins handled:**
- `dcn` — drawing control: color change (`cc`), pen size (`pstc`), eraser size (`estc`), tool mode (`mc`), slide add (`as`), slide change (`sc`), clear all (`ea`)
- `cw` — canvas write: stroke down (`d`), move (`m`), up (`u`), delete strokes (`dlos`), copy-paste (`pdo2`), pointer (`p`), zoom (`zm`), pan (`pn`)
- `mcn` — meta control: clear everything (`cle`), checkpoint (`cp`)

**Tool normalisation (`_normalizeTool`):**
Maps raw strings like `"markerh"`, `"2"`, `"eraser"`, `"r"` → standardised: `"marker"`, `"highlighter"`, `"eraser"`, `"rectangle"`, `"circle"`, `"arrow"`, `"line"`, `"dashed-line"`

**Key methods:**
```js
stateManager.buildSlideIndex()
// Must be called once after timeline.load().
// Builds deck, slideTimeline, eventSidMap.

stateManager.getSlideAtTime(timeMs)
// Returns {index, sid, url, bc} for the active slide at timeMs.

stateManager.updateSlideForTime(timeMs)
// Updates currentSid/currentSlideUrl. Returns true if changed.

stateManager.rebuildState(targetMs)
// Replays ALL events from 0 to targetMs from scratch.
// Called on every seek backward. Expensive but correct.

stateManager.applyEvent(ev)
// Applies a single event to current state.
// Called by PlayerController for each new event during forward playback.

stateManager.getAllStrokes()
// Returns all strokes for the current slide (completed + active).
// Hooked by pen override system (see below).
```

---

### `PDFRenderer` (from p4r3.js, extended)

Renders slide backgrounds onto `#slide-canvas`.

UA's slide images are NOT real PDFs despite the `.pdf` extension in URLs. They are server-pre-rendered PNG/JPEG images served with a `?page=N&t=xxx` query. They are fetched as images and rendered with `createImageBitmap()`.

**Image cache:** `Map<url, ImageBitmap>`, max 40 entries (LRU eviction).

**Retry logic:** On fetch failure, retries with exponential backoff (1s, 2s, 4s… up to 30s). Never shows error to user, keeps buffering spinner visible.

**Slide layout:** After drawing, stores `slideLayout = {x, y, w, h}` — the actual pixel bounds of the slide image within the canvas (letterboxed with black bars). This is critical: `CanvasRenderer` uses it to correctly map normalised 0–1 stroke coordinates to actual canvas pixels.

**`clearToBackground()`:** When no slide URL exists, fills a 16:9 area with `bgColor` (default `#202022`). Updates `slideLayout` to match. This is what you see on whiteboard-only slides.

**PDF fallback (`loadPdf` + `renderPdfPage`):** If a `.pdf` URL is provided (optional), loads it via PDF.js. When a slide has no image URL, renders the corresponding PDF page instead.

**Extended method added:** `loadPdf(url)` and `renderPdfPage(pageNum)` — not in original p4r3.js, added to support the `?pdf=` URL param.

---

### `CanvasRenderer` (from c9r4.js)

Renders all annotation strokes onto `#draw-canvas` on top of the slide.

**Dirty checking:** Only redraws when something actually changed (stroke count, active stroke count, pointer position, zoom/pan key). Skips frames where nothing changed — important for performance.

**Coordinate mapping (`_mapToSlide`):**
```js
// Converts normalised 0-1 coords to actual canvas pixels
// using PDFRenderer's slideLayout bounds
x_pixel = slideLayout.x + nx * slideLayout.w
y_pixel = slideLayout.y + ny * slideLayout.h
```
This is why the slideLayout must be kept in sync — strokes must land inside the slide area, not in the black letterbox bars.

**Stroke rendering by tool type:**
- `marker` — smooth bezier quadratic curves, 1.8× size scaling, full opacity
- `highlighter` — same curves, 10× width, 35% opacity, fades out after 3.5 seconds (for teacher's highlight-then-fade effect)
- `eraser` — `globalCompositeOperation = "destination-out"` — punches holes in the draw canvas, revealing slide underneath
- `rectangle` — bounding box of all points → `ctx.rect()`
- `circle` / `ellipse` — bounding box → `ctx.ellipse()`
- `line` / `dashed-line` — first point to last point, dashed uses `setLineDash()`
- `arrow` — line + arrowhead at endpoint

**Color boosting (`_boostColor`):** Converts dark/muddy colors to vivid equivalents by forcing saturation=1.0 and lightness between 0.55–0.75. Prevents instructor's dim colors from being invisible.

**Pointer rendering:** Pulsing red dot with radial gradient halos. Pulse driven by `_pointerPulse` counter incremented each frame.

**Pen override hook:**
```js
// StateManager.prototype.getAllStrokes is monkey-patched:
const _origGetAllStrokes = StateManager.prototype.getAllStrokes;
StateManager.prototype.getAllStrokes = function(){
  const strokes = _origGetAllStrokes.call(this);
  if(!penOverrideMode) return strokes;
  return strokes.map(s => {
    if(s.isE || s.tool==="eraser") return s;  // never recolor erasers
    return Object.assign({}, s, {color: penOverrideColor});
  });
};
```
This makes all strokes render in the user's chosen pen color without mutating the stored stroke data.

---

### `PlayerController` (reconstructed from pk05.js usage)

The main RAF loop. Drives everything.

**`_tick()` — called every animation frame:**
```
1. Get video.currentTime (ms)
2. Convert to event timeline ms via timeline.videoToEventMs()
3. Detect backward seek: if videoMs dropped by >200ms OR eventMs regressed by >500ms
   → rebuildState(eventMs) from scratch
   → updateSlide
4. Forward playback:
   → timeline.getNewEvents(eventMs) — get new events since last tick
   → for each: stateManager.applyEvent(ev) — update state
   → detect slide change → updateSlide if needed
   → else: canvasRenderer.render() — just redraw strokes
```

**`_updateSlide(eventMs)`:**
```
if currentSlideUrl exists:
    pdfRenderer.renderSlide(url) → then canvasRenderer.render()
else:
    pdfRenderer.clearToBackground()
    canvasRenderer.render()
```

---

## Layout & CSS

### Overall structure
```
body (flex column)
├── #loading-overlay       — full-screen spinner during load
├── #setup-section         — shown before any video loaded
└── #player-section        — shown when playing
    ├── #player-body (flex row)
    │   ├── #slide-panel   — black background, centers the slide container
    │   │   ├── #slide-container  (16:9 aspect-ratio enforced by CSS)
    │   │   │   ├── #slide-canvas (z:1)
    │   │   │   └── #draw-canvas  (z:2, pointer-events:none)
    │   │   ├── #buffering-overlay
    │   │   ├── #gesture-feedback
    │   │   └── #stats-bar
    │   └── #right-sidebar (300px fixed width)
    │       ├── #webcam-wrap
    │       │   └── <video id="webcam-video">
    │       └── #notes-panel
    │           ├── #notes-header (+ Note | ⬆ Import | ⬇ Export)
    │           ├── #notes-modal (overlay for import/export dialogs)
    │           ├── #note-composer (hidden until + Note clicked)
    │           └── #notes-list
    ├── #controls-bar       — seek bar, play/pause, volume, speed, fullscreen
    └── #color-toolbar      — board color swatches + pen color swatches
```

### Canvas sizing
`resizeCanvases()` is called on load and on `ResizeObserver`. It reads `#slide-container`'s `getBoundingClientRect()` (which is already constrained to 16:9 by CSS `aspect-ratio: 16/9`) and sets `canvas.width = rect.width * devicePixelRatio`. This ensures crisp rendering on high-DPI screens without the canvas stretching beyond the slide area.

### 16:9 constraint (zoom fix)
The key CSS that prevents the "fully zoomed/stretched" bug:
```css
#slide-panel {
  display: flex; align-items: center; justify-content: center;
}
#slide-container {
  width: 100%; height: 100%;
  max-width: calc(100vh * 16 / 9 * 0.92);
  aspect-ratio: 16 / 9;
  max-height: 100%;
}
```
The canvas is constrained to 16:9 and never stretches beyond the player panel. Black bars appear on the sides of `#slide-panel` (background `#111`) rather than inside the canvas.

---

## Setup Screen (Two Modes)

### Online tab
- Paste `.webm` video URL + optional PDF URL → Load
- `data.json` is auto-fetched from same path as video: `videoUrl.replace("output.webm", "data.json")`
- Also reads `?video=` and `?pdf=` URL params on page load for direct linking
- Also reads `?t=SECONDS` to auto-seek on load (used by PDF export links)

### Offline tab
Three drag-and-drop zones:
1. `output.webm` — the video
2. `data.json` — required, event log
3. PDF — optional slide PDF

Files are read via `URL.createObjectURL()` — never uploaded anywhere, stay local. `blob:` URLs are created in memory and work identically to HTTP URLs for the canvas/video APIs.

---

## Controls

| Control | Behavior |
|---|---|
| Play/Pause button | `videoEl.play()` / `videoEl.pause()` |
| Rewind 10s / Forward 10s | `videoEl.currentTime ± 10` |
| Seek bar | Range input; dragging sets `videoEl.currentTime`. During drag, `seekBarEl._drag = true` so `timeupdate` events don't fight the drag |
| Buffer bar | Semi-transparent overlay showing buffered range from `videoEl.buffered` |
| Seek tooltip | Shows time at hover position |
| Volume slider | `videoEl.volume` |
| Mute button | Saves `dataset.lastVol` before muting, restores on unmute |
| Speed selector | `videoEl.playbackRate` (0.5×, 0.75×, 1×, 1.25×, 1.5×, 2×) |
| Fullscreen | `playerSection.requestFullscreen()` |
| Keyboard | Space = play/pause, ←/→ = ±5s, ↑/↓ = volume |
| Mobile double-tap | Left half = -10s, right half = +10s, single tap = play/pause |

**Idle hiding:** Controls bar fades out after 3 seconds of no mouse movement during playback. Reappears on any movement. Color toolbar stays always visible.

**Buffering:** When `videoEl` fires `waiting`, the spinner overlay appears and video is paused. When a slide image is fetching, same spinner appears and video is paused until the slide loads. Both flags (`isSlide`, `isVideo`) must be false before video resumes.

---

## Color Toolbar

Always visible bar below the controls.

### Board color
Sets `pdfRenderer.bgColor`. Affects the background fill of `clearToBackground()` (whiteboard slides) and the letterbox color behind slide images. 8 presets (charcoal, black, midnight blue, chalkboard green, warm dark, indigo, paper, white) + custom color input.

### Pen color override
Two modes via `penOverrideMode` boolean:
- **LECTURE** — uses each stroke's original `color` from data.json (instructor's real colors)
- **OVERRIDE** — monkey-patches `StateManager.getAllStrokes()` to swap all stroke colors to `penOverrideColor` before rendering

8 preset colors (white, yellow, green, red, cyan, orange, violet, pink) + custom input. Low contrast warning appears if pen color is too close to board color (luminance diff < 60).

---

## Notes System

### Data structure
Each note stored as:
```js
{
  id: "abc123xyz",          // unique: Date.now().toString(36) + random
  ts: "00:08:37",           // formatted timestamp string
  videoSec: 517,            // integer seconds into video
  type: "note" | "bookmark" | "important",
  text: "user's text",      // can be empty for pure bookmarks
  slideUrl: "https://..."   // UA slide image URL active at this moment
                            // (captured from stateManager at save time)
}
```

### localStorage persistence
Key is derived from video URL:
```js
// For https://uamedia.uacdn.net/lesson-raw/VIDEO_ID/output.webm
// → key = "ua_notes_lesson-raw_VIDEO_ID_output.webm"
```
Each lecture gets its own key so notes from different lectures don't mix. Stored as JSON array.

### Adding a note
1. Click **+ Note** (or press `N`)
2. Composer opens, auto-stamps current `Math.floor(videoEl.currentTime)` as seconds
3. Also calls `getSlideUrlAtSec(sec)` immediately — queries `stateManager.getSlideAtTime(eventMs)` to capture the slide URL active at that exact moment
4. User types text, picks type, hits Save (or Ctrl+Enter)
5. Note inserted in sorted order by `videoSec`, saved to localStorage

### Clicking a note
Calls `seekToNoteTime(sec)`:
1. `videoEl.currentTime = sec`
2. `stateManager.rebuildState(eventMs)` — rebuilds all annotations at that timestamp
3. `videoEl.play()` — resumes playback

This is the critical part — without `rebuildState()`, seeking would show the current annotation state at whatever time the video was at before, not the correct annotations for the note's timestamp.

### Export JSON
Produces:
```json
{
  "version": 1,
  "videoUrl": "https://...",
  "exportedAt": "2026-09-10T...",
  "notes": [ ...note objects... ]
}
```
Downloaded as `ua-notes-YYYY-MM-DD.json`. Can be imported back at any time.

### Export PDF
Full flow per note:
1. **Pause video**, save current position and play state
2. For each note (with progress bar):
   - `videoEl.currentTime = note.videoSec` → await `seeked` event
   - `stateManager.rebuildState(eventMs)` — annotations correct at this time
   - `stateManager.updateSlideForTime(eventMs)` — determine active slide
   - If slide URL changed: `pdfRenderer.renderSlide(url)` — wait for full render
   - `canvasRenderer.invalidate()` → `canvasRenderer.render()` — draw annotations
   - Create offscreen `<canvas>`, draw `slideCanvas` then `drawCanvas` on it (composite)
   - `composite.toDataURL("image/jpeg", 0.88)` — screenshot with slide + annotations
3. PDF page layout (A4, dark theme):
   - Type badge (color-coded) + timestamp (top row)
   - Clickable link: `player.html?video=URL&t=SECONDS` → opens player at that time
   - Screenshot (16:9, full width)
   - Divider line
   - Note text
   - Page number footer
4. **Restore video** to original position → `rebuildState()` → resume if was playing
5. Save PDF via `doc.save()`

jsPDF (2.5.1) is loaded lazily from cdnjs on first PDF export.

### Import JSON
1. Drop `.json` file or browse
2. Parses and previews note count
3. Two modes:
   - **Merge** — skips duplicates (matched by `videoSec + text`), appends new notes, re-sorts
   - **Replace** — wipes current notes, replaces with imported array
4. Saves to localStorage, re-renders list

### Auto-seek from PDF links
`checkAutoSeek()` runs on every `setupNotesUI()` call. Reads `?t=` from URL params. If found, waits for `loadedmetadata` then calls `seekToNoteTime(t)`. This is what makes PDF clickable links work — they open `player.html?video=URL&t=517` and the player auto-seeks to second 517 with correct annotations.

---

## Data Flow: Online Load

```
User pastes video URL
    │
    ▼
loadSession(videoUrl, pdfUrl)
    │
    ├── fetch videoUrl.replace("output.webm", "data.json")
    │       → raw JSON
    │
    └── loadLesson(raw, videoUrl, pdfUrl)
            │
            ├── TimelineEngine.load(raw)     — parse events
            ├── StateManager.buildSlideIndex()  — build slide deck + maps
            ├── PDFRenderer(slideCanvas)     — create renderer
            ├── CanvasRenderer(drawCanvas)   — create renderer
            ├── pdfRenderer.loadPdf(pdfUrl)  — optional PDF fallback
            ├── stateManager.updateSlideForTime(0)
            ├── pdfRenderer.renderSlide(firstSlideUrl)  — show first slide
            ├── videoEl.src = videoUrl  → load → play
            ├── on loadedmetadata: rebuildState(0) + render
            ├── PlayerController.start()     — begin RAF loop
            ├── setupControls()              — wire seek bar, volume, etc.
            ├── setupColorToolbar()          — wire board/pen swatches
            └── setupNotesUI(videoUrl)       — load notes from localStorage
```

## Data Flow: Offline Load

```
User drops files
    │
    ├── output.webm → URL.createObjectURL() → blob:// URL (video)
    ├── data.json   → jsonFile.text() → JSON.parse() → raw
    └── *.pdf       → URL.createObjectURL() → blob:// URL (PDF)
            │
            └── loadLesson(raw, blobVideoUrl, blobPdfUrl)
                    (identical to online from here)
```

---

## Known Behaviors & Edge Cases

### `syncLagMs = 2000`
There's a 2-second offset between video time and event time by default. This matches UA's own player behavior. Configurable via `localStorage.setItem("ua_sync_lag_ms", "0")` to `"15000"`.

### `p_time` unit detection
If `maxTime - minTime > 10,000,000` → events are in microseconds → divide by 1000 to get ms. Fixed bug in original t3k1.js that used raw `maxTime` instead of `maxTime - minTime`.

### Slide ID (`sid`) vs. canvas ID
In UA's architecture, each slide has a `sid` (stable identity) and each `cw` (canvas write) event has a `canvasId` / `panelId`. The `eventSidMap` correctly routes strokes to their slide. Multiple canvases can share a slide, and slides can have strokes from different panels.

### `pdo2` (copy-paste strokes)
When teacher copies and pastes a stroke, a `pdo2` event arrives with `copyId` and `pasteId` + delta offsets `dx`, `dy`, scale `sx`, `sy`. The StateManager finds the original stroke, transforms its points, and stores it as a new stroke.

### `dlos` (delete strokes by ID)
Batch deletes specific stroke IDs. Used when teacher selects and deletes objects.

### Backward seek detection
`if(videoMs < this._lastVideoMs - 200)` — 200ms tolerance handles normal playback jitter. On detection: `rebuildState()` replays all events from scratch to `eventMs`.

### Canvas composite operation reset
After drawing eraser strokes (`destination-out`), `ctx.globalCompositeOperation` is reset to `"source-over"` and `globalAlpha` to `1` to prevent contaminating subsequent draws.

### Color boosting
`_boostColor()` converts instructor's dark colors (which look fine on their tablet but appear muddy on a dark board) to vivid saturated equivalents while preserving hue. Near-white colors and near-black colors are mapped to white and light grey respectively.

### Blob URL cleanup
Before loading a new lesson while one is already playing, any existing `blob:` URL on `videoEl.src` is revoked via `URL.revokeObjectURL()` to free memory.

---

## Adding Features / Modifying

### To add a new toolbar button
1. Add `<button>` to `#controls-bar` in HTML
2. Add listener in `setupControls()`

### To add a new note type
1. Add `<button class="note-type-btn" data-type="yourtype">` in `#note-composer`
2. Add entry to `typeColors` and `typeLabels` in `exportPDF()`
3. Add CSS for `.note-ts.yourtype` in the notes list styles

### To change sync lag
```js
localStorage.setItem("ua_sync_lag_ms", "0")   // 0 = no lag
// Range: 0 to 15000 ms
// Default: 2000
```

### To debug event structure
Open browser console (if you remove `sx91.js` devtools blocking). The original UA player had devtools protection — our player has none.

### To add a new plugin handler
In `StateManager._applyCw()` or `_applyDcnDraw()`, add a new `if(e === "yourEvent")` branch.

---

## What's NOT Implemented

- **Zoom/pan rendering** — `zm` and `pn` events are tracked in `stateManager.zoom/panX/panY` but `CanvasRenderer._applyTransform()` is a stub that returns early if zoom=1. Would need to transform the canvas context before drawing strokes.
- **Text objects** — some UA lessons have text overlay objects, not handled
- **Image objects** — pasted images on the canvas, not handled
- **Multiple instructor panels** — handled partially (strokes are bucketed by `panelId`) but complex multi-panel layouts may not be pixel-perfect
- **Annotation export at time of note-save** — currently for offline mode, `slideUrl` stored in the note may be a `blob:` URL that expires when the tab closes. PDF export for offline sessions won't have the slide screenshot (shows placeholder instead).

---

## Deployment

Just drop `player.html` anywhere. No server, no dependencies, no build step.

For `tradeedify.site` / `saireddy.site`:
- Upload to `/ua/player.html` or wherever
- Link with `?video=...&pdf=...` params
- Notes are stored in the user's own browser localStorage — per device, not synced

For completely offline use (no internet):
- Save `player.html` to disk
- Open in browser
- Use the 📁 Offline tab
- jsPDF for PDF export requires internet on first use (loaded from cdnjs), but is cached by the browser after that

---

## Features Added (v4 + v5)

### v4 — Four new features

#### ✏️ User Drawing on Top of Slides

A third canvas (`#user-draw-canvas`, z-index 10) sits above both `#slide-canvas` and `#draw-canvas`. It is `pointer-events: none` by default and becomes `pointer-events: all` only when drawing mode is active.

**Toggle:** `D` key or the ✏️ button in `#controls-bar` (id `draw-mode-ctrl-btn`). Also wired to `#dtb-toggle` in the floating toolbar.

**Floating toolbar (`#draw-toolbar`):** Positioned absolute inside `#slide-panel`, top-left. Hidden by default (`opacity:0; pointer-events:none`), shown via `.visible` class.

Tools:
- `#dtb-pen` — smooth quadratic bezier curves, `source-over`
- `#dtb-hi` — highlighter, 6× width, 33% opacity (`color + '55'`)
- `#dtb-eraser` — `destination-out` composite op

State lives in `userDrawState`:
```js
{
  active: false,
  tool: 'pen',         // pen | highlighter | eraser
  color: '#FF3131',
  size: 4,
  strokes: [],         // [{tool, color, size, pts:[{x,y}]}]  — pixel coords, not normalised
  current: null,       // stroke in progress
  redoStack: [],       // filled by undoUserDraw()
}
```

Pointer events use `setPointerCapture` so strokes don't break on fast moves. Coordinates are raw canvas pixels (no normalisation — user canvas is not tied to the slide layout).

`Ctrl+Z` = undo last stroke. 🗑 button = clear all with confirm.

User strokes are composited into highlight reel exports (drawn onto the recording canvas alongside `slideCanvas` + `drawCanvas`).

**Key functions:** `initUserDraw()`, `toggleUserDraw(on)`, `setUserTool(tool)`, `redrawUserCanvas()`, `undoUserDraw()`

---

#### 🗂 Chapter / Slide Navigator

A second tab in the right sidebar (`#stab-chapters`) showing every slide change in order.

**Sidebar tab system:** `#sidebar-tabs` bar with `.stab-btn` buttons and `.stab-pane` divs. Notes tab = `#stab-notes`, Slides tab = `#stab-chapters`.

Chapter list is built by `buildChapterNav()`:
- Iterates `stateManager.slideTimeline`, deduplicates by `sid`
- Computes approximate video seconds by inverting `timeline.videoToEventMs()`:
  ```js
  raw  = evMs * divider + minTime
  frac = (raw - t0) / eventSpanUs
  videoSec = (frac * vidDurMs + syncLagMs) / 1000
  ```
- Each `.chapter-item` has a 72×40 thumbnail, timestamp, and slide index

Thumbnails captured by `captureChapterThumbs()` — reads from `pdfRenderer.imgCache` (no extra fetches), draws to a 144×81 offscreen canvas, stores as JPEG dataURL in `_chapterThumbs` Map (keyed by `sid`).

Active slide highlighted via `highlightChapterAtTime()` → called from `PlayerController._tick()` only when `updateSlideForTime()` returns `true` (slide actually changed).

Called: `buildChapterNav()` + `captureChapterThumbs()` triggered 800ms after `loadLesson()` completes.

**Key functions:** `buildChapterNav()`, `captureChapterThumbs()`, `highlightChapterItem(sid)`, `highlightChapterAtTime()`

---

#### 🎬 Highlight Reel Export

Select specific notes and export a `.webm` clip reel from those moments.

**Flow:**
1. Click 🎬 in Notes header → enters selection mode (`.sel-mode` class on note items)
2. Click notes to toggle check (`.checked` class, stored in `_reelSelected` Set of note IDs)
3. Click ✅ Done → `openReelModal()` — shows clip window selector (±15/30/60s) and estimated duration
4. Click Export Reel → `startReelExport()`

**Export mechanism:**
- Builds `intervals[]` from selected notes ± window, merges overlapping intervals
- Creates an offscreen `composite` canvas same size as `slideCanvas`
- `composite.captureStream(25)` → `MediaRecorder` (prefers `vp9`, falls back to `vp8`/`webm`)
- For each interval: seeks video → plays → RAF loop draws `slideCanvas + drawCanvas + user-draw-canvas` onto composite each frame
- `recorder.stop()` → `Blob` → download as `.webm`

**Modal:** `#reel-modal` (fixed overlay, z-index 200). Progress bar at `#reel-progress-fill`.

**Key functions:** `toggleReelMode()`, `enterReelSelectionMode()`, `openReelModal()`, `startReelExport()`

---

#### ⚡ Multi-tab Note Sync

`BroadcastChannel` keeps notes in sync across browser tabs open on the same lecture.

Channel name: `'ua_player_sync_' + _notesKey` (so each lecture gets its own channel).

Message types:
- `ping` — announce presence on load
- `pong` — reply to ping
- `notes_update` — push full `_notesCache` array after any save

`saveNotes()` calls `broadcastNotesUpdate()` inline after every localStorage write. Incoming `notes_update` messages replace `_notesCache`, re-save to localStorage, and call `renderNotesList()`.

`#sync-badge` in Notes header shows `.offline` (grey) or active (green) state with peer count.

Each tab has a `_syncId` (random 6-char string) to filter out its own echoed messages.

**Key functions:** `initNoteSync()`, `broadcastNotesUpdate()`, `updateSyncBadge(online, tooltip)`

---

### v5 — Three new features

#### 🖼 Seek Thumbnail Preview

Hovering the seek bar shows a 160×90 canvas preview (`#seek-thumb-canvas`) of the slide at that position without seeking the video.

**HTML:** `#seek-thumbnail-preview` (absolute, `bottom: 32px`) contains `#seek-thumb-canvas` + `#seek-thumb-time`. Shown via `.show` class (opacity transition).

**Logic:**
- Mousemove on `#seek-bar` → compute `pos` (0–1) → `sec = pos * bar.max`
- Key = `Math.round(sec / 5) * 5` (quantized to 5-second buckets to avoid redundant work)
- If cached: draw immediately via `_drawThumb()`
- Else: call `_generateThumb(key, tctx, canvas)` async

`_generateThumb()`:
1. Converts `videoSec` → `eventMs` via `timeline.videoToEventMs()`
2. Calls `stateManager.getSlideAtTime(eventMs)` to get slide URL — **no video seek**
3. If slide is in `pdfRenderer.imgCache`: draws from bitmap directly
4. Else: `fetch(slideInfo.url)` → `createImageBitmap()` → also stores in `pdfRenderer.imgCache`
5. Scales to 160×90 via offscreen canvas → stores dataURL in `_thumbCache` Map

`_thumbGenBusy` flag prevents concurrent generations. Thumbnail replaces the plain `#seek-tooltip` text box (plain tooltip hidden while thumbnail is available).

**Key functions:** `initSeekThumbnail()`, `_generateThumb(videoSec, tctx, canvas)`, `_drawThumb(tctx, canvas, dataUrl)`

---

#### 🔴 Note Markers on Seek Bar

Colored dots rendered as absolute-positioned divs inside `#note-markers-layer` (a div overlaid on the seek bar, `pointer-events: none` on the layer, `pointer-events: all` on individual markers).

Marker colors match note types:
- `bookmark` → `#60a5fa` (blue)
- `important` → `#fbbf24` (amber)
- `note` → `#34d399` (green)

Position: `left = (note.videoSec / dur) * 100 + '%'`

Each marker has a `.note-marker-tooltip` child (opacity 0 → 1 on hover) showing note text truncated to 30 chars.

Click on a marker calls `seekToNoteTime(note.videoSec)`.

`renderNoteMarkers()` is called:
- After every `renderNotesList()` (via wrapper reassignment: `renderNotesList = function() { _origRenderNotesList(); setTimeout(renderNoteMarkers, 100); }`)
- On `loadedmetadata` + `durationchange` events (hooked in `_hookNoteMarkersOnLoad()`)
- 1200ms after `loadLesson()` completes

**Key functions:** `renderNoteMarkers()`, `_hookNoteMarkersOnLoad()`

---

#### 🌐 Translate Notes

Translates all notes with text into a target language using the Claude API.

**UI:** 🌐 button (`#translate-btn`) in Notes header toggles `#translate-panel` (slides in below notes list inside `#stab-notes`). Language selector `#translate-lang` has 10 options: Hindi, Telugu, Tamil, Spanish, French, German, Japanese, Chinese, Arabic, Portuguese.

**Flow (`translateAllNotes(targetLang)`):**
1. Filter `_notesCache` to notes with non-empty `.text`
2. Render skeleton rows immediately (with ⏳ spinner text)
3. Build single prompt: all notes numbered with timestamps, ask Claude to return a JSON array of translated strings in order
4. POST to `https://api.anthropic.com/v1/messages` with `claude-sonnet-4-6`, `max_tokens: 1024`
5. Strip markdown fences, `JSON.parse()` the response
6. Fill in each row's `.translate-result` div

All notes batched into one API call regardless of count. Original text shown as `.translate-orig` (italic, dimmed). Translation shown as `.translate-result`.

**Key functions:** `initTranslate()`, `translateAllNotes(targetLang)`

---

## Bug Fixes (v5 patch)

Three stack overflow bugs introduced during v4/v5 feature injection:

### 1. `renderNotesList` infinite recursion
**Cause:** Patch used `function renderNotesList()` declaration which is hoisted — `_origRenderNotesList` and the new wrapper both pointed to the hoisted function, causing infinite mutual recursion.
**Fix:** Changed to variable assignment `renderNotesList = function() { ... }` so `_origRenderNotesList` captures the original binding before reassignment.

### 2. `StateManager.prototype.updateSlideForTime` flooding RAF queue
**Cause:** Prototype patch fired `requestAnimationFrame(highlightChapterAtTime)` on every call. During `rebuildState()` this fires hundreds of times, flooding the RAF queue → stack overflow.
**Fix:** Removed prototype patch entirely. `highlightChapterAtTime()` is now called directly from `PlayerController._tick()` only when `updateSlideForTime()` returns `true`.

### 3. Dead `_origSaveNotes` stubs
**Cause:** Two comment/code fragments from an earlier patch approach left `const _origSaveNotes = saveNotes` in script scope with nothing referencing it — confusing but not the crash cause. Cleaned up for clarity.

---

## Current HTML Structure (additions)

```
#player-body
├── #slide-panel
│   ├── #slide-container
│   │   ├── #slide-canvas      (z:1 — slide backgrounds)
│   │   ├── #draw-canvas       (z:2 — instructor annotations)
│   │   └── #user-draw-canvas  (z:10 — user's own drawings) ← NEW
│   └── #draw-toolbar          (absolute, top-left) ← NEW
├── #right-sidebar
│   ├── #webcam-wrap
│   └── #notes-panel
│       ├── #sidebar-tabs      ← NEW
│       │   ├── .stab-btn [Notes]
│       │   └── .stab-btn [Slides]
│       ├── #stab-notes (tab pane) ← NEW wrapper
│       │   ├── #notes-header  (+ translate-btn, reel-select-btn, sync-badge)
│       │   ├── #notes-modal
│       │   ├── #note-composer
│       │   ├── #notes-list
│       │   └── #translate-panel ← NEW
│       └── #stab-chapters (tab pane) ← NEW
│           └── #chapter-list
├── #controls-bar
│   └── #draw-mode-ctrl-btn    ← NEW (before fullscreen btn)
└── #reel-modal (fixed overlay) ← NEW

#seek-bar-container
├── #buffer-bar
├── #note-markers-layer        ← NEW
├── #seek-bar
├── #seek-hover-dot
└── #seek-thumbnail-preview    ← NEW
    ├── #seek-thumb-canvas
    └── #seek-thumb-time
```

---

## Key Global Variables (additions)

| Variable | Type | Purpose |
|---|---|---|
| `userDrawState` | Object | All user drawing state — tool, color, size, strokes array, redo stack |
| `_udcEl` | HTMLCanvasElement | Reference to `#user-draw-canvas` |
| `_udcCtx` | CanvasRenderingContext2D | 2D context of user canvas |
| `_chapterThumbs` | Map<sid, dataURL> | Cached 144×81 JPEG thumbnails per slide sid |
| `_reelSelectionMode` | boolean | Whether highlight reel selection is active |
| `_reelSelected` | Set<id> | Note IDs selected for reel export |
| `_syncChannel` | BroadcastChannel | Cross-tab sync channel |
| `_syncId` | string | Random ID to filter own messages from broadcast |
| `_syncPeers` | number | Count of other tabs connected |
| `_thumbCache` | Map<videoSec, dataURL> | Seek thumbnail cache (5s buckets) |
| `_thumbGenBusy` | boolean | Prevents concurrent thumbnail generation |

---

## Keyboard Shortcuts (additions)

| Key | Action |
|---|---|
| `D` | Toggle user drawing mode |
| `Ctrl+Z` | Undo last user drawing stroke |
