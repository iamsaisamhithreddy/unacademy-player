<?php header("Content-Type: application/javascript"); header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0"); header("Pragma: no-cache"); ?>
/* ============================================================================
 * UA Lecture Player — copy/paste + transform + undo replay (v3)
 * ----------------------------------------------------------------------------
 * Must load AFTER state-manager.js and renderers.js. Overrides StateManager's
 * cw handling and wraps CanvasRenderer.render.
 *
 * This version is a straight port of what Unacademy's own replay engine does
 * (player.uacdn.net/liveweb/v4036 — main.chunk.js / utils~main.chunk.js).
 *
 * OBJECT MODEL (same as UA)
 *   Every object = RAW points (exactly as drawn, never modified) + an affine
 *   matrix (UA calls it domMatrix). What you see = matrix applied to raw.
 *   `stroke.points` here is just a cache of that product for the renderer.
 *
 * WHAT v2 GOT WRONG (and why the grids looked different from Unacademy)
 *   1. tf "sl" (resize) was treated as ABSOLUTE on a snapshot. It is an
 *      INCREMENTAL delta — UA does  obj.domMatrix.preMultiplySelf(m)  for
 *      every sl event. A resize drag sends dozens of tiny steps (0.98, 0.99,
 *      1.002 …); v2 kept only the last step, so a group the teacher shrank
 *      to ~55% height stayed at ~100%. Result: every table too big, and
 *      everything pasted from them too big / overlapping.
 *   2. pdo2 path matrix {sx,sy,shx,shy,dx,dy} is the source object's FULL
 *      matrix at copy time (+ paste offset). UA applies it to the source's
 *      RAW points (preProcessor.objectPool[copyId].ppts). v2 applied it to
 *      the already-transformed points -> double scale / double offset.
 *   3. "un" only popped the last stroke. UA walks the slide's action list
 *      backwards and undoes the newest of: paste (one pdo2), delete-objects,
 *      a whole transform block (s…e), or a stroke. Implemented the same way.
 *
 * v4 ADDITIONS (verified against lecture 2: 843 pastes, 26 partial erases)
 *   4. Partial eraser. UA never stamps an eraser stroke onto the board. On the
 *      eraser's "u" event it attaches a copy of the eraser path to every object
 *      it touched (oids[k] gets eraser id eids[k]). That path then moves /
 *      scales / pastes WITH the object (pdo2.path.eraserPaths). Old recordings
 *      without oids fall back to UA's bbox-overlap test. Rendering matches UA's
 *      draw(): identity-matrix objects erase on the shared canvas, transformed
 *      ones are drawn + erased on an offscreen canvas first.
 *   5. Laser highlighter (mode "highlighter", NOT "marker-h"). UA never makes
 *      it a board object: it lives on the temp canvas, only the latest stroke
 *      shows, 50% alpha, width 7, and it vanishes on the next laser stroke,
 *      a mode change, or a seek.
 *   7. Off-edge points (x or y slightly > 1) were mistaken for pixel coords and
 *      flung to the opposite edge, drawing long stray lines. Fixed (v8).
 *   6. Permanent highlighter ("marker-h"): fixed stroke size 7 (UA onDown),
 *      drawn in the marker's width unit at 50% alpha, and its width scales
 *      with the object's matrix (UA shouldScaleStroke). Previously it was
 *      10x the pen size at 35%.
 *
 * MATRIX SPACE
 *   UA matrices live in canvas PIXELS (e = dx*W, f = dy*H). We store them in
 *   normalised board space; conversion only matters for shear/rotation:
 *     a' = a, d' = d, b' = b*W/H, c' = c*H/W, e' = dx, f' = dy
 * ========================================================================== */
(function () {
  "use strict";

  if (typeof StateManager !== "function" || typeof CanvasRenderer !== "function") {
    console.warn("[ua-fix] StateManager/CanvasRenderer not found — patch not applied. Check script order in player.html.");
    return;
  }

  var PW = 750, PH = 422, ASPECT = PW / PH;
  var num = function (v, dflt) { var n = parseFloat(v); return Number.isFinite(n) ? n : dflt; };
  var norm = function (s) { return String(s == null ? "" : s).replace(/[-_]/g, ""); };

  // ── 2D affine helpers: [a,b,c,d,e,f]  x' = a*x + c*y + e ; y' = b*x + d*y + f
  var IDENT = function () { return [1, 0, 0, 1, 0, 0]; };
  // mul(A,B) = A·B  (apply B first, then A)  — DOMMatrix.preMultiplySelf(A) on B
  var mul = function (A, B) {
    return [
      A[0] * B[0] + A[2] * B[1],
      A[1] * B[0] + A[3] * B[1],
      A[0] * B[2] + A[2] * B[3],
      A[1] * B[2] + A[3] * B[3],
      A[0] * B[4] + A[2] * B[5] + A[4],
      A[1] * B[4] + A[3] * B[5] + A[5]
    ];
  };
  var fromPixelMatrix = function (sx, shy, shx, sy, dx, dy) {
    return [sx, shy * ASPECT, shx / ASPECT, sy, dx, dy];
  };
  var isIdent = function (M) {
    return M[0] === 1 && M[1] === 0 && M[2] === 0 && M[3] === 1 && M[4] === 0 && M[5] === 0;
  };

  // Recompute the render cache from raw + matrix.
  function refresh(stroke) {
    var M = stroke.m, raw = stroke.raw;
    if (!M || isIdent(M)) { stroke.points = raw.map(function (p) { return { x: p.x, y: p.y }; }); return; }
    stroke.points = raw.map(function (p) {
      return { x: M[0] * p.x + M[2] * p.y + M[4], y: M[1] * p.x + M[3] * p.y + M[5] };
    });
  }
  // A freshly drawn stroke shares one array for raw+points while it's being
  // drawn; split them the first time a matrix touches it.
  function ensureRaw(stroke) {
    if (!stroke.raw || stroke.raw === stroke.points) {
      stroke.raw = stroke.points.map(function (p) { return { x: p.x, y: p.y }; });
    }
    if (!stroke.m) stroke.m = IDENT();
  }
  function refreshEraser(er) {
    var M = er.m;
    er.points = er.raw.map(function (p) {
      return { x: M[0] * p.x + M[2] * p.y + M[4], y: M[1] * p.x + M[3] * p.y + M[5] };
    });
  }
  function premultiply(stroke, T) {
    ensureRaw(stroke);
    stroke.m = mul(T, stroke.m);
    refresh(stroke);
    if (stroke.erasers) for (var i = 0; i < stroke.erasers.length; i++) {
      stroke.erasers[i].m = mul(T, stroke.erasers[i].m); refreshEraser(stroke.erasers[i]);
    }
  }
  // current bbox of a stroke's displayed points
  function bboxOf(pts) {
    var b = { xmin: Infinity, ymin: Infinity, xmax: -Infinity, ymax: -Infinity };
    for (var i = 0; i < pts.length; i++) {
      var p = pts[i]; if (!isFinite(p.x) || !isFinite(p.y)) continue;
      if (p.x < b.xmin) b.xmin = p.x; if (p.x > b.xmax) b.xmax = p.x;
      if (p.y < b.ymin) b.ymin = p.y; if (p.y > b.ymax) b.ymax = p.y;
    }
    return b;
  }

  var zCounter = 0;

  // ── per-slide state ────────────────────────────────────────────────────────
  StateManager.prototype.getSlideState = function (sid) {
    if (!this.strokesBySid.has(sid)) {
      this.strokesBySid.set(sid, { strokes: new Map(), activeByPanel: new Map(), byOid: new Map(), selection: null, actions: [] });
    }
    var st = this.strokesBySid.get(sid);
    if (!st.byOid) st.byOid = new Map();
    if (!st.actions) st.actions = [];
    if (st.selection === undefined) st.selection = null;
    return st;
  };

  // Global object pool (UA: preProcessor.objectPool) — lets a paste find its
  // source even across slides or after the source was deleted.
  StateManager.prototype._pool = function () {
    if (!this._objPool) this._objPool = new Map();
    return this._objPool;
  };

  StateManager.prototype._erPool = function () {
    if (!this._eraserPool) this._eraserPool = new Map();
    return this._eraserPool;
  };

  StateManager.prototype._findByOid = function (byOid, id) {
    if (id == null) return null;
    var direct = byOid.get(String(id));
    if (direct) return direct;
    var want = norm(id), it = byOid.entries(), r;
    while (!(r = it.next()).done) if (norm(r.value[0]) === want) return r.value[1];
    return null;
  };

  StateManager.prototype._addStroke = function (ss, stroke) {
    if (!stroke._key) stroke._key = "o_" + (stroke.oid || "") + "_" + (++zCounter);
    if (stroke.z == null) stroke.z = ++zCounter;
    ss.strokes.set(stroke._key, stroke);
    if (stroke.oid) ss.byOid.set(String(stroke.oid), stroke);
  };

  StateManager.prototype._forgetStroke = function (ss, stroke) {
    if (!stroke) return;
    if (stroke._key) ss.strokes.delete(stroke._key);
    if (stroke.oid && ss.byOid.get(String(stroke.oid)) === stroke) ss.byOid.delete(String(stroke.oid));
    var it = ss.strokes.entries(), r;
    while (!(r = it.next()).done) if (r.value[1] === stroke) ss.strokes.delete(r.value[0]);
    var ia = ss.activeByPanel.entries(), ra;
    while (!(ra = ia.next()).done) if (ra.value[1] === stroke) ss.activeByPanel.delete(ra.value[0]);
  };

  // Put a previously removed stroke back, keeping original draw order.
  StateManager.prototype._restoreStroke = function (ss, stroke) {
    if (!stroke) return;
    stroke.oid && ss.byOid.set(String(stroke.oid), stroke);
    var arr = Array.from(ss.strokes.values());
    if (arr.indexOf(stroke) < 0) arr.push(stroke);
    arr.sort(function (a, b) { return (a.z || 0) - (b.z || 0); });
    ss.strokes = new Map();
    for (var i = 0; i < arr.length; i++) {
      if (!arr[i]._key) arr[i]._key = "o_" + (arr[i].oid || "") + "_" + (++zCounter);
      ss.strokes.set(arr[i]._key, arr[i]);
    }
  };

  // ── transform events (UA handleObjectTransformEvent) ────────────────────────
  StateManager.prototype._applyTf = function (inner, ss) {
    var t = inner.t, payload = inner.data || {};

    if (t === "s") {                         // SELECT
      var ids = payload.ids || [], items = [];
      for (var i = 0; i < ids.length; i++) {
        var st = this._findByOid(ss.byOid, ids[i]);
        if (st && st.points && st.points.length) {
          ensureRaw(st);
          items.push({ stroke: st, m0: st.m.slice(),
                       e0: (st.erasers || []).map(function (er) { return [er, er.m.slice()]; }) });
        }
      }
      var action = { kind: "tf", aId: inner.aId || null, items: items, undone: false };
      ss.actions.push(action);
      ss.selection = items.length ? { items: items, action: action } : null;
      return;
    }
    if (t === "e") { ss.selection = null; return; }   // END

    var sel = ss.selection;
    if (!sel || !sel.items.length) return;
    var T = null;

    if (t === "ts") {                        // TRANSLATE — incremental
      var dx = num(payload.dx, 0), dy = num(payload.dy, 0);
      if (!dx && !dy) return;
      T = [1, 0, 0, 1, dx, dy];
    } else if (t === "sl") {                 // SCALE — incremental matrix m
      var m = payload.m;
      if (m) {
        T = fromPixelMatrix(num(m.sx, 1), num(m.shy, 0), num(m.shx, 0), num(m.sy, 1), num(m.dx, 0), num(m.dy, 0));
      } else {
        // old clients: plain scale about (cx,cy)
        var sx = num(payload.sx, 1), sy = num(payload.sy, 1), cx = num(payload.cx, 0), cy = num(payload.cy, 0);
        T = [sx, 0, 0, sy, cx * (1 - sx), cy * (1 - sy)];
      }
    } else if (t === "r") {                  // ROTATE about (cx,cy), degrees, pixel space
      var dg = num(payload.dg, 0);
      if (!dg) return;
      var rad = dg * Math.PI / 180, cs = Math.cos(rad), sn = Math.sin(rad);
      var px = num(payload.cx, 0) * PW, py = num(payload.cy, 0) * PH;
      // translate(p)·rotate·translate(-p) in pixels
      var e = px - cs * px + sn * py, f = py - sn * px - cs * py;
      T = fromPixelMatrix(cs, sn, -sn, cs, e / PW, f / PH);
    }
    if (!T) return;
    for (var k = 0; k < sel.items.length; k++) premultiply(sel.items[k].stroke, T);
    this.rev = (this.rev | 0) + 1;
  };

  // ── paste (UA createPasteData / onPasteObjects) ────────────────────────────
  StateManager.prototype._applyPdo2 = function (inner, ss, timeMs) {
    var path = inner.path || (inner.paths && inner.paths[0]) || {};
    var srcId = path.copyId != null ? path.copyId : path.sourceId;
    if (srcId == null) return;

    var pool = this._pool();
    var src = pool.get(String(srcId)) || pool.get(norm(srcId));
    if (!src) {
      var live = this._findByOid(ss.byOid, srcId);
      if (!live) {
        var all = this.strokesBySid.values(), n;
        while (!(n = all.next()).done) { live = this._findByOid(n.value.byOid, srcId); if (live) break; }
      }
      if (live) src = { raw: live.raw || live.points, tool: live.tool, color: live.color, size: live.size };
    }
    if (!src || !src.raw || !src.raw.length) return;

    var newId = path.pasteId != null ? String(path.pasteId) : "pdo2_" + timeMs + "_" + (++zCounter);
    // path matrix = source's full matrix at copy time + paste offset,
    // applied to the source's RAW points (never to its displayed points)
    var M = fromPixelMatrix(num(path.sx, 1), num(path.shy, 0), num(path.shx, 0), num(path.sy, 1),
                            num(path.dx, 0), num(path.dy, 0));
    var size = num(path.strokeSize, src.size);
    var copy = {
      oid: newId, aId: inner.aId || null,
      color: src.color, size: size, tool: src.tool,
      timeMs: timeMs, isActive: false,
      raw: src.raw.map(function (p) { return { x: p.x, y: p.y }; }),
      m: M, points: null
    };
    refresh(copy);
    var eps = path.eraserPaths || [], erPool = this._erPool();
    if (eps.length) {
      copy.erasers = [];
      for (var q = 0; q < eps.length; q++) {
        var ep = eps[q], pe = erPool.get(String(ep.id)) || erPool.get(norm(ep.id));
        if (!pe) continue;
        var er = {
          id: String(ep.cloneId || ep.id), raw: pe.raw, size: num(ep.strokeSize, pe.size),
          m: fromPixelMatrix(num(ep.sx, 1), num(ep.shy, 0), num(ep.shx, 0), num(ep.sy, 1), num(ep.dx, 0), num(ep.dy, 0))
        };
        refreshEraser(er);
        copy.erasers.push(er);
        erPool.set(er.id, pe); erPool.set(norm(er.id), pe);
      }
    }
    this._addStroke(ss, copy);
    pool.set(newId, { raw: copy.raw, tool: copy.tool, color: copy.color, size: copy.size });
    pool.set(norm(newId), pool.get(newId));
    ss.actions.push({ kind: "paste", aId: inner.aId || null, stroke: copy, undone: false });
    this.rev = (this.rev | 0) + 1;
  };

  // ── undo (UA processUndoEvent) ─────────────────────────────────────────────
  StateManager.prototype._undoAction = function (ss, a) {
    a.undone = true;
    if (a.kind === "stroke" || a.kind === "paste") {
      this._forgetStroke(ss, a.stroke);
      if (ss.laser === a.stroke) ss.laser = null;
      if (a.attached) for (var k = 0; k < a.attached.length; k++) {
        var ow = a.attached[k].obj, ix = ow.erasers ? ow.erasers.indexOf(a.attached[k].er) : -1;
        if (ix >= 0) ow.erasers.splice(ix, 1);
      }
    } else if (a.kind === "dlos") {
      for (var i = 0; i < a.strokes.length; i++) this._restoreStroke(ss, a.strokes[i]);
    } else if (a.kind === "tf") {
      for (var j = 0; j < a.items.length; j++) {
        var it = a.items[j]; it.stroke.m = it.m0.slice(); refresh(it.stroke);
        for (var q = 0; q < (it.e0 || []).length; q++) { it.e0[q][0].m = it.e0[q][1].slice(); refreshEraser(it.e0[q][0]); }
      }
      if (ss.selection && ss.selection.action === a) ss.selection = null;
    }
  };

  StateManager.prototype._applyUndo = function (inner, ss) {
    var acts = ss.actions, i;
    if (inner.useAId) {
      var target = null;
      for (i = acts.length - 1; i >= 0; i--) {
        if (!acts[i].undone && acts[i].aId) { target = acts[i].aId; break; }
      }
      if (target == null) return;
      for (; i >= 0; i--) {
        if (acts[i].undone) continue;
        if (acts[i].aId !== target) break;
        this._undoAction(ss, acts[i]);
      }
    } else {
      for (i = acts.length - 1; i >= 0; i--) {
        var a = acts[i];
        if (a.undone) continue;
        this._undoAction(ss, a);
        // UA keeps walking only past laser-highlighter strokes
        if (!(a.kind === "stroke" && a.stroke && a.stroke.laser)) break;
      }
    }
    this.rev = (this.rev | 0) + 1;
  };

  // ── partial eraser (UA handleEraserOnUp) ──────────────────────────────────
  StateManager.prototype._attachEraser = function (ss, eStroke, inner) {
    if (ss.byOid.get(String(eStroke.oid)) === eStroke) ss.byOid.delete(String(eStroke.oid));
    var raw = eStroke.points.map(function (p) { return { x: p.x, y: p.y }; });
    var size = this.eraserSize, erPool = this._erPool(), attached = [], self = this;
    var attach = function (obj, id) {
      if (!obj || obj.tool === "eraser" || obj.tool === "laser") return;
      var er = { id: String(id), raw: raw, size: size, m: IDENT() };
      refreshEraser(er);
      (obj.erasers || (obj.erasers = [])).push(er);
      attached.push({ obj: obj, er: er });
      var pe = { raw: raw, size: size };
      erPool.set(String(id), pe); erPool.set(norm(id), pe);
    };
    var oids = inner.oids, eids = inner.eids;
    if (Array.isArray(oids) && oids.length && Array.isArray(eids) && eids.length) {
      for (var k = 0; k < oids.length; k++) attach(self._findByOid(ss.byOid, oids[k]), eids[k] != null ? eids[k] : eStroke.oid + "_" + k);
    } else {
      // older recordings: UA replays by bbox overlap (pad = stroke/W + 0.01)
      var eb = bboxOf(raw), padX = size / PW + 0.01, padY = size / PH + 0.01;
      eb.xmin -= padX; eb.xmax += padX; eb.ymin -= padY; eb.ymax += padY;
      var arr = Array.from(ss.strokes.values());
      for (var i = arr.length - 1; i >= 0; i--) {
        var ob = bboxOf(arr[i].points || []);
        if (ob.xmax >= eb.xmin && ob.xmin <= eb.xmax && ob.ymax >= eb.ymin && ob.ymin <= eb.ymax)
          attach(arr[i], eStroke.oid + "_" + i);
      }
    }
    // record on the stroke's action so undo can detach
    for (var a = ss.actions.length - 1; a >= 0; a--) if (ss.actions[a].stroke === eStroke) { ss.actions[a].attached = attached; break; }
  };

  // ── main cw dispatcher ────────────────────────────────────────────────────
  StateManager.prototype._applyCw = function (data, timeMs, eventSid) {
    var inner = data.data || data;
    var e = inner.e;
    if (e === "start" || e === "down") e = "d";
    else if (e === "move" || e === "drag") e = "m";
    else if (e === "end" || e === "up") e = "u";
    else if (["clear_draw", "clear", "ea", "erase_all", "cd"].indexOf(e) >= 0) e = "cl";
    else if (e === "undo") e = "un";
    else if (["pdo", "o", "obj", "object", "shape"].indexOf(e) >= 0) e = "pdo2";

    var panelId = (data.id != null ? data.id : (inner.id != null ? inner.id : 0));
    var aId = inner.aId || null;

    if (e === "zm") {
      var vz = inner.v || {};
      if (typeof vz === "number") { this.zoom = vz; return; }
      this.zoom = num(vz.u != null ? vz.u : vz.zoom, 1);
      this.panX = num(vz.x, 0); this.panY = num(vz.y, 0);
      return;
    }
    if (e === "pn") {
      var vp = inner.v || {};
      this.panX = num(vp.x, 0); this.panY = num(vp.y, 0);
      return;
    }

    var ss = this.getSlideState(eventSid);
    var activeByPanel = ss.activeByPanel, byOid = ss.byOid;

    if (e === "tf") { this._applyTf(inner, ss); return; }

    if (e === "cl" || e === "c") {
      ss.strokes.clear(); activeByPanel.clear(); byOid.clear();
      ss.selection = null; ss.actions = []; ss.laser = null;
      this.rev = (this.rev | 0) + 1;
      return;
    }
    if (e === "un") { this._applyUndo(inner, ss); return; }
    if (e === "r") {
      var abandoned = activeByPanel.get(panelId);
      if (abandoned) this._forgetStroke(ss, abandoned);
      activeByPanel.delete(panelId);
      return;
    }
    if (e === "dlos") {
      var delIds = inner.ids || (inner.data && inner.data.ids) || [], gone = [];
      for (var di = 0; di < delIds.length; di++) {
        var doomed = this._findByOid(byOid, delIds[di]);
        if (doomed) { this._forgetStroke(ss, doomed); gone.push(doomed); }
      }
      ss.actions.push({ kind: "dlos", aId: aId, strokes: gone, undone: false });
      ss.selection = null;
      this.rev = (this.rev | 0) + 1;
      return;
    }
    if (e === "pdo2") { this._applyPdo2(inner, ss, timeMs); return; }

    if (e === "cc" || e === "color_change") {
      var cc = inner.c || inner.color || inner.col;
      if (typeof cc === "string") this.penColor = cc;
      return;
    }
    if (["mc", "brush_change", "tc", "tool_change", "mode", "mode_change", "shape_change"].indexOf(e) >= 0) {
      var tt = inner.shapeType || inner.shape || inner.m || inner.tool || inner.mode || inner.type || inner.b;
      if (tt != null) this.activeTool = this._normalizeTool(tt);
      return;
    }

    // ── point events ────────────────────────────────────────────────────────
    var pt = inner.p || inner.point || {};
    if (pt.shouldDraw === false) return;
    var rx = num(pt.x != null ? pt.x : pt.px, NaN);
    var ry = num(pt.y != null ? pt.y : pt.py, NaN);
    if (!Number.isFinite(rx) || !Number.isFinite(ry)) return;

    // UA sends normalised 0..1 coords; a pen dragged past the right/bottom edge
    // gives values like 1.003. The old "> 1 means pixels" rule turned those into
    // 1.003/422 = 0.002 and drew a line across the whole board. No recording has
    // ever contained real pixel values (max seen 1.04), so only treat > 2 as px.
    var nx = rx > 2 ? rx / PW : rx;
    var ny = ry > 2 ? ry / PH : ry;
    if ((e === "d" || e === "m") && Math.abs(nx) < 0.0001 && Math.abs(ny) < 0.0001) return;

    this.pointerX = nx; this.pointerY = ny; this.pointerVisible = true;
    if (e === "p") return;

    if (e === "d") {
      if (activeByPanel.has(panelId)) {                 // unterminated previous stroke
        var prev = activeByPanel.get(panelId);
        activeByPanel.delete(panelId);
        if (prev.points.length > 0 && prev.tool !== "eraser" && prev.tool !== "laser") { prev.isActive = false; this._addStroke(ss, prev); }
      }
      var st2 = inner.shapeType || inner.shape || inner.m || inner.tool || inner.mode || inner.type || inner.b ||
                pt.shapeType || pt.shape || pt.m || pt.tool;
      if (st2 != null) this.activeTool = this._normalizeTool(st2);

      var oid = pt.oid || pt.objectId || pt.id || aId || ("anon_" + timeMs + "_" + panelId);
      var pts = [{ x: nx, y: ny }];
      var stroke = {
        oid: String(oid), aId: aId,
        color: this.penColor, size: this.penSize, tool: this.activeTool,
        timeMs: timeMs, isActive: true,
        points: pts, raw: pts, m: null, z: ++zCounter,
        laser: this._rawMode === "highlighter"   // UA L.HIGHLIGHTER (temporary laser)
      };
      // UA onDown: marker-h (and laser) use a fixed stroke size of 7, not the pen size
      if (this._rawMode === "marker-h") stroke.size = 7;
      if (stroke.laser) {
        stroke.tool = "laser";
        // only the newest laser stroke is ever visible (UA clears tmp canvas)
        var sit = this.strokesBySid.values(), sn;
        while (!(sn = sit.next()).done) sn.value.laser = null;
      }
      activeByPanel.set(panelId, stroke);
      byOid.set(String(oid), stroke);
      // pool shares the raw array, so it fills in as the stroke is drawn
      var pool = this._pool();
      var pe = { raw: pts, tool: stroke.tool, color: stroke.color, size: stroke.size };
      pool.set(String(oid), pe); pool.set(norm(oid), pe);
      ss.actions.push({ kind: "stroke", aId: aId, stroke: stroke, undone: false });
      this.rev = (this.rev | 0) + 1;
    } else if (e === "m") {
      var act = activeByPanel.get(panelId);
      if (act && pt.d !== false) act.points.push({ x: nx, y: ny });
    } else if (e === "u") {
      if (activeByPanel.has(panelId)) {
        var fin = activeByPanel.get(panelId);
        activeByPanel.delete(panelId);
        if (fin.points.length > 0) {
          fin.isActive = false;
          fin.timeMs = timeMs;
          if (fin.tool === "laser") {
            ss.laser = fin;
            if (byOid.get(String(fin.oid)) === fin) byOid.delete(String(fin.oid));
          } else if (fin.tool === "eraser") {
            this._attachEraser(ss, fin, inner);
          } else {
            this._addStroke(ss, fin);
          }
          this.rev = (this.rev | 0) + 1;
        }
      }
    }
  };

  // ── getAllStrokes: committed strokes in draw order + in-progress ones ──────
  StateManager.prototype.getAllStrokes = function () {
    if (!this.currentSid) return [];
    var st = this.getSlideState(this.currentSid), res = Array.from(st.strokes.values());
    var ia = st.activeByPanel.values(), r;
    while (!(r = ia.next()).done) if (r.value.points.length > 0) res.push(r.value);
    if (st.laser && st.laser.points.length) res.push(st.laser);
    return res;
  };

  // ── "ea" on the dcn channel ────────────────────────────────────────────────
  var _origDcn = StateManager.prototype._applyDcnDraw;
  StateManager.prototype._applyDcnDraw = function (data) {
    var r = _origDcn.call(this, data);
    var ev = data && data.e;
    if (ev === "mc" && data.m != null) {
      this._rawMode = String(data.m);
      if (this._rawMode !== "highlighter") {         // UA copyTemp(true)
        var lit = this.strokesBySid.values(), ln;
        while (!(ln = lit.next()).done) if (ln.value.laser) { ln.value.laser = null; this.rev = (this.rev | 0) + 1; }
      }
    }
    if (["ea", "clear", "clear_draw", "cl", "c", "erase_all"].indexOf(ev) >= 0) {
      var it = this.strokesBySid.values(), n;
      while (!(n = it.next()).done) {
        if (n.value.byOid) n.value.byOid.clear();
        n.value.selection = null; n.value.actions = []; n.value.laser = null;
      }
      this.rev = (this.rev | 0) + 1;
    }
    return r;
  };

  // ── seeking rebuilds from scratch: drop the pool, force a repaint ─────────
  var _origRebuild = StateManager.prototype.rebuildState;
  StateManager.prototype.rebuildState = function (targetMs) {
    this._objPool = new Map(); this._eraserPool = new Map(); this._rawMode = null;
    var r = _origRebuild.call(this, targetMs);
    var rit = this.strokesBySid.values(), rn;
    while (!(rn = rit.next()).done) rn.value.laser = null;
    this.rev = (this.rev | 0) + 1;
    return r;
  };

  // ── renderer: laser + attached eraser paths (UA draw helper "C") ──────────
  var _origDrawStroke = CanvasRenderer.prototype._drawStroke;
  CanvasRenderer.prototype._eraserPass = function (ctx, ers) {
    var sl = this._slideLayout, scX = (sl && sl.w > 0) ? sl.w / PW : this.width / PW;
    for (var i = 0; i < ers.length; i++) {
      var er = ers[i], pts = er.points; if (!pts || !pts.length) continue;
      var M = er.m, sx = Math.hypot(M[0], M[1] / ASPECT), sy = Math.hypot(M[2] * ASPECT, M[3]);
      ctx.save();
      ctx.globalCompositeOperation = "destination-out"; ctx.globalAlpha = 1;
      ctx.strokeStyle = "rgba(0,0,0,1)"; ctx.fillStyle = "rgba(0,0,0,1)";
      ctx.lineCap = "round"; ctx.lineJoin = "round";
      // same size unit as the marker (strokeSize * W / baseWidth in UA)
      ctx.lineWidth = Math.max(1, (er.size || 5) * 1.8 * scX * Math.min(Math.abs(sx), Math.abs(sy)));
      ctx.beginPath();
      var p0 = this._mapToSlide(pts[0].x, pts[0].y);
      if (pts.length < 3) {
        ctx.arc(p0.x, p0.y, ctx.lineWidth / 2, 0, Math.PI * 2); ctx.fill();
      } else {
        ctx.moveTo(p0.x, p0.y);
        for (var k = 1; k < pts.length - 1; k++) {
          var a = this._mapToSlide(pts[k].x, pts[k].y), b = this._mapToSlide(pts[k + 1].x, pts[k + 1].y);
          ctx.quadraticCurveTo(a.x, a.y, (a.x + b.x) / 2, (a.y + b.y) / 2);
        }
        ctx.stroke();
      }
      ctx.restore();
    }
  };
  CanvasRenderer.prototype._drawStroke = function (stroke, t) {
    if (stroke.tool === "laser") {
      var s2 = Object.assign({}, stroke, { tool: "marker", size: 7 });
      var c0 = this.ctx; c0.save(); c0.globalAlpha = 0.5;
      var r0 = _origDrawStroke.call(this, s2, t);
      c0.restore(); return r0;
    }
    if (stroke.tool === "highlighter") {
      // UA MARKER_HIGHLIGHTER ("marker-h"): marker width, colour+"80" (50%),
      // and — unlike every other tool — its width scales with the object.
      var hm = stroke.m || [1, 0, 0, 1, 0, 0];
      var hs = Math.min(Math.abs(Math.hypot(hm[0], hm[1] / ASPECT)), Math.abs(Math.hypot(hm[2] * ASPECT, hm[3])));
      var s3 = Object.assign({}, stroke, { tool: "marker", size: (stroke.size || 1) * hs });
      var c3 = this.ctx; c3.save(); c3.globalAlpha = 0.5;
      var r3;
      if (stroke.erasers && stroke.erasers.length) { s3.erasers = stroke.erasers; r3 = this._drawStroke(s3, t); }
      else r3 = _origDrawStroke.call(this, s3, t);
      c3.restore(); return r3;
    }
    var ers = stroke.erasers;
    if (!ers || !ers.length) return _origDrawStroke.call(this, stroke, t);
    if (!stroke.m || isIdent(stroke.m)) {
      var r1 = _origDrawStroke.call(this, stroke, t);
      this._eraserPass(this.ctx, ers);
      return r1;
    }
    // transformed object: draw + erase in isolation, then composite
    if (!this._off) { this._off = document.createElement("canvas"); this._offCtx = this._off.getContext("2d"); }
    var off = this._off, oc = this._offCtx, main = this.ctx;
    if (off.width !== this.canvas.width || off.height !== this.canvas.height) { off.width = this.canvas.width; off.height = this.canvas.height; }
    oc.setTransform(1, 0, 0, 1, 0, 0); oc.clearRect(0, 0, off.width, off.height);
    oc.setTransform(main.getTransform());
    this.ctx = oc;
    var r2;
    try { r2 = _origDrawStroke.call(this, stroke, t); this._eraserPass(oc, ers); }
    finally { this.ctx = main; }
    main.save(); main.setTransform(1, 0, 0, 1, 0, 0); main.globalCompositeOperation = "source-over";
    main.drawImage(off, 0, 0); main.restore();
    return r2;
  };

  var _origRender = CanvasRenderer.prototype.render;
  CanvasRenderer.prototype.render = function (state, slideLayout, currentTimeMs) {
    if (state && state.rev !== this._lastRevSeen) {
      this._lastRevSeen = state.rev;
      this._forceRedraw = true;
    }
    return _origRender.call(this, state, slideLayout, currentTimeMs);
  };

  window.UA_COPYFIX_VERSION = 11;
  console.info("[ua-fix] v11 copy/paste + transforms + undo + partial eraser + laser active");
  // Small badge so you can SEE which copy-fix the browser actually loaded.
  var showBadge = function () {
    var b = document.createElement("div");
    b.textContent = "copy-fix v11 loaded";
    b.style.cssText = "position:fixed;left:8px;bottom:8px;z-index:99999;background:#16a34a;color:#fff;" +
      "font:600 12px/1 sans-serif;padding:6px 10px;border-radius:6px;opacity:.95;transition:opacity 1s";
    document.body.appendChild(b);
    setTimeout(function () { b.style.opacity = "0"; }, 5000);
    setTimeout(function () { b.remove(); }, 6200);
  };
  if (document.body) showBadge(); else document.addEventListener("DOMContentLoaded", showBadge);
})();
