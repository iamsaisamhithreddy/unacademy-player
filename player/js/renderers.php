<?php header("Content-Type: application/javascript"); ?>
"use strict";

// ════════════════════════════════════════════════════════════════════════════
// PDF RENDERER  (p4r3.js)  [DEBUG BUILD]
// ════════════════════════════════════════════════════════════════════════════
// Two layouts are tracked, and the difference matters:
//
//   slideLayout — the rect the SLIDE IMAGE occupies (letterboxed by the
//                 image's own aspect ratio). Use it for drawing the picture.
//   boardLayout — the 16:9 rect the RECORDING BOARD occupies. Stroke
//                 coordinates are normalised 0..1 against this, so all
//                 annotation rendering must map through it. Using
//                 slideLayout here squashes strokes into the image rect
//                 whenever the slide PNG isn't 16:9, which shifts every
//                 annotation vertically.
//
// `annoLayout` is the accessor the rest of the app should pass to
// CanvasRenderer.render().
// ════════════════════════════════════════════════════════════════════════════
class PDFRenderer {
  constructor(canvas){
    this.canvas=canvas;this.ctx=canvas.getContext("2d");
    this.currentUrl=null;this.renderVersion=0;
    this.imgCache=new Map();this.bgColor="#202022";
    this.slideLayout={x:0,y:0,w:0,h:0};
    this.boardLayout={x:0,y:0,w:0,h:0};
    // PDF.js state (used when a real .pdf is provided instead of per-slide images)
    this.pdfDoc=null;this.totalPages=0;this._serial=0;this._renderTask=null;
  }

  _updateBoardLayout(){
    const tgt=16/9, cr=this.canvas.width/this.canvas.height;
    let w,h,x,y;
    if(cr>tgt){h=this.canvas.height;w=h*tgt;x=(this.canvas.width-w)/2;y=0;}
    else{w=this.canvas.width;h=w/tgt;x=0;y=(this.canvas.height-h)/2;}
    this.boardLayout={x,y,w,h};
    return this.boardLayout;
  }

  get annoLayout(){ return (this.boardLayout&&this.boardLayout.w>0)?this.boardLayout:this.slideLayout; }

  async loadPdf(pdfUrl){
    if(!pdfUrl)return;
    this.pdfDoc=await pdfjsLib.getDocument(pdfUrl).promise;
    this.totalPages=this.pdfDoc.numPages;
  }
  // Called by PlayerController when slide URL changes.
  // UA slides come in two flavours:
  //   image  — https://uadoc.uacdn.net/<uuid>.jpg
  //   PDF    — https://uadoc.uacdn.net/common/<x>/<file>.pdf?page=N&t=…
  // The second kind can't go through fetch→createImageBitmap (that throws on a
  // PDF and _renderImage then retries forever behind the buffering spinner),
  // so PDF pages are rendered with pdf.js, exactly like UA's own player does.
  async renderSlide(rawUrl){
    if(!rawUrl)return;
    this.currentUrl=rawUrl;
    const version=++this.renderVersion;
    if(this.imgCache.has(rawUrl)){this._drawBmp(this.imgCache.get(rawUrl));return;}
    if(/\.pdf(\?|$)/i.test(rawUrl)){ await this._renderPdfSlide(rawUrl,version); return; }
    const failedAt=this._failedUrls&&this._failedUrls.get(rawUrl);
    if(failedAt&&Date.now()-failedAt<60000){ this.clearToBackground(); return; }
    await this._renderImage(rawUrl,version);
  }

  // Load (and cache) the lecture PDF from its URL. Resolves to null when the
  // network copy can't be read (offline / CORS) so callers can fall back to
  // the PDF the user picked in the Offline tab (this.pdfDoc).
  _loadRemotePdf(baseUrl){
    if(!this._remoteDocs)this._remoteDocs=new Map();
    if(!this._remoteDocs.has(baseUrl)){
      const p=(typeof pdfjsLib!=="undefined")
        ? pdfjsLib.getDocument({url:baseUrl}).promise.catch(err=>{console.warn("[slides] could not load PDF from URL, using local PDF if provided:",baseUrl,err&&err.message);return null;})
        : Promise.resolve(null);
      this._remoteDocs.set(baseUrl,p);
    }
    return this._remoteDocs.get(baseUrl);
  }

  // Unacademy never renders PDFs in the browser: its player asks the image
  // CDN for a ready-made page picture by appending these params to the slide
  // URL (seen in the HAR: …pdf?page=N&t=…&fm=webp&fit=clip&auto=compress&w=1080,
  // served with Access-Control-Allow-Origin: *). Use the same first.
  _cdnPageUrl(rawUrl){
    const w=Math.min(1920,Math.max(1080,Math.round(this.canvas.width||1080)));
    return rawUrl+(rawUrl.includes("?")?"&":"?")+"fm=webp&fit=clip&auto=compress&w="+w;
  }
  async _cdnPageBitmap(rawUrl){
    if(this._cdnDown&&Date.now()-this._cdnDown<60000)return null;   // CDN unreachable recently
    try{
      const ctl=typeof AbortController!=="undefined"?new AbortController():null;
      const to=ctl?setTimeout(()=>ctl.abort(),8000):null;
      const resp=await fetch(this._cdnPageUrl(rawUrl),ctl?{signal:ctl.signal}:{});
      if(to)clearTimeout(to);
      if(!resp.ok)throw new Error("HTTP "+resp.status);
      return await createImageBitmap(await resp.blob());
    }catch(err){
      this._cdnDown=Date.now();
      console.warn("[slides] page image from Unacademy CDN unavailable, rendering the PDF instead:",err&&err.message);
      return null;
    }
  }
  // pdf.js paints the page onto white. Some dark-template PDFs leave a 1-2 px
  // strip at an edge uncovered, which shows as a thin white line. If an edge
  // line is pure white but the page just inside it is dark, copy the inner
  // line over it (a no-op on normal white-background slides).
  _fixWhiteEdges(ctx,w,h){
    try{
      const lum=(d)=>{let s=0,n=0;for(let i=0;i<d.length;i+=16){s+=0.299*d[i]+0.587*d[i+1]+0.114*d[i+2];n++;}return n?s/n:255;};
      const REF=16, MAXK=12;   // reference line 16 px inside; repair up to 12 px
      const edges=[
        k=>[0,k,w,1],        k=>[0,h-1-k,w,1],     // top, bottom rows
        k=>[k,0,1,h],        k=>[w-1-k,0,1,h]      // left, right columns
      ];
      for(const at of edges){
        const ref=ctx.getImageData(...at(REF));
        if(lum(ref.data)>=90)continue;              // page isn't dark there: leave it alone
        if(lum(ctx.getImageData(...at(0)).data)<=200)continue;   // no white strip at this edge
        for(let k=0;k<MAXK;k++){                     // walk inward over the bright strip
          const r=at(k);
          if(lum(ctx.getImageData(...r).data)<=Math.max(90,lum(ref.data)+25))break;
          ctx.putImageData(ref,r[0],r[1]);
        }
      }
    }catch(_){}
  }

  // Render one PDF page to a cached offscreen canvas (null if unavailable).
  //   1) Unacademy's CDN page image   2) pdf.js on the PDF URL
  //   3) pdf.js on the PDF picked in the Offline tab
  async _pdfPageCanvas(rawUrl){
    if(this.imgCache.has(rawUrl))return this.imgCache.get(rawUrl);
    const pm=/^(.*?\.pdf)(?:\?(.*))?$/i.exec(rawUrl); if(!pm)return null;
    const mm=/(?:^|&)page=(\d+)/.exec(pm[2]||""), pageNum=mm?parseInt(mm[1],10):1;
    if(!this._pdfPagePending)this._pdfPagePending=new Map();
    if(this._pdfPagePending.has(rawUrl))return this._pdfPagePending.get(rawUrl);
    const remember=(img)=>{
      // full-page images are big: keep at most 12 of them
      if(!this._pdfPageKeys)this._pdfPageKeys=[];
      this.imgCache.set(rawUrl,img);this._pdfPageKeys.push(rawUrl);
      while(this._pdfPageKeys.length>12)this.imgCache.delete(this._pdfPageKeys.shift());
      return img;
    };
    const job=(async()=>{
      const bmp=await this._cdnPageBitmap(rawUrl);
      if(bmp)return remember(bmp);
      let doc=await this._loadRemotePdf(pm[1]);
      if(!doc||pageNum>doc.numPages)doc=this.pdfDoc;                // offline fallback
      if(!doc||pageNum<1||pageNum>doc.numPages)return null;
      const page=await doc.getPage(pageNum);
      const base=page.getViewport({scale:1});
      const vp=page.getViewport({scale:Math.max(1600,this.canvas.width||0)/base.width});
      const off=document.createElement("canvas");
      off.width=Math.ceil(vp.width);off.height=Math.ceil(vp.height);
      const octx=off.getContext("2d");
      octx.fillStyle="#ffffff";octx.fillRect(0,0,off.width,off.height);
      await page.render({canvasContext:octx,viewport:vp}).promise;
      this._fixWhiteEdges(octx,off.width,off.height);
      return remember(off);
    })().catch(err=>{console.warn("[slides] PDF page render failed:",rawUrl,err&&err.message);return null;})
       .finally(()=>this._pdfPagePending.delete(rawUrl));
    this._pdfPagePending.set(rawUrl,job);
    return job;
  }

  async _renderPdfSlide(rawUrl,version){
    if(window.showBuffering)window.showBuffering();
    try{
      const off=await this._pdfPageCanvas(rawUrl);
      if(version!==this.renderVersion)return;
      if(off)this._drawBmp(off);
      else this.clearToBackground();   // nothing to draw: plain board, no endless spinner
    }finally{
      if(window.hideBuffering)window.hideBuffering();
    }
  }
  // Render a PDF page by 1-based index (fallback when no per-slide image URL)
  async renderPdfPage(pageNum){
    if(!this.pdfDoc||pageNum<1||pageNum>this.totalPages)return;
    const mine=++this._serial;
    if(this._renderTask){try{this._renderTask.cancel();}catch(_){}this._renderTask=null;}
    this._clearCanvas();
    this.ctx.fillStyle=this.bgColor;this.ctx.fillRect(0,0,this.canvas.width,this.canvas.height);
    try{
      const pg=await this.pdfDoc.getPage(pageNum);
      if(mine!==this._serial)return;
      const base=pg.getViewport({scale:1});
      const scale=Math.min(this.canvas.width/base.width,this.canvas.height/base.height);
      const vp=pg.getViewport({scale});
      const ox=(this.canvas.width-vp.width)/2,oy=(this.canvas.height-vp.height)/2;
      this.slideLayout={x:ox,y:oy,w:vp.width,h:vp.height};
      this._updateBoardLayout();
      this._renderTask=pg.render({canvasContext:this.ctx,viewport:vp,transform:[1,0,0,1,ox,oy]});
      await this._renderTask.promise;
      this._renderTask=null;
    }catch(e){if(e&&e.name==="RenderingCancelledException")return;}
  }
  _drawBmp(bmp){
    this._clearCanvas();
    const scale=Math.min(this.canvas.width/bmp.width,this.canvas.height/bmp.height);
    const w=bmp.width*scale,h=bmp.height*scale;
    const x=(this.canvas.width-w)/2,y=(this.canvas.height-h)/2;
    this.ctx.drawImage(bmp,x,y,w,h);
    this.slideLayout={x,y,w,h};
    this._updateBoardLayout();
  }
  async _renderImage(url,version){
    let attempt=0;
    while(true){
      if(version!==this.renderVersion)return;
      try{
        let bmp;
        if(this.imgCache.has(url)){bmp=this.imgCache.get(url);}
        else{
          this._clearCanvas();
          if(window.showBuffering)window.showBuffering();
          const resp=await fetch(url);
          if(!resp.ok)throw new Error("HTTP "+resp.status);
          const blob=await resp.blob();
          bmp=await createImageBitmap(blob);
          this.imgCache.set(url,bmp);
          if(this.imgCache.size>40){const f=this.imgCache.keys().next().value;this.imgCache.delete(f);}
        }
        if(version!==this.renderVersion)return;
        if(window.hideBuffering)window.hideBuffering();
        this._drawBmp(bmp);return;
      }catch(err){
        attempt++;
        // Give up after 3 tries (offline / blocked) instead of spinning forever:
        // show the plain board and remember the failure for a minute.
        if(attempt>=3){
          if(!this._failedUrls)this._failedUrls=new Map();
          this._failedUrls.set(url,Date.now());
          console.warn("[slides] image slide unavailable (offline or blocked):",url);
          if(window.hideBuffering)window.hideBuffering();
          if(version===this.renderVersion)this.clearToBackground();
          return;
        }
        const delay=1000*attempt;
        if(window.showBuffering)window.showBuffering();
        await new Promise(r=>setTimeout(r,delay));
        if(version!==this.renderVersion){if(window.hideBuffering)window.hideBuffering();return;}
      }
    }
  }
  async prefetch(rawUrl){
    if(!rawUrl||this.imgCache.has(rawUrl))return;
    if(this._failedUrls&&this._failedUrls.has(rawUrl))return;
    if(/\.pdf(\?|$)/i.test(rawUrl)){ await this._pdfPageCanvas(rawUrl); return; }
    try{const resp=await fetch(rawUrl);if(!resp.ok)return;const blob=await resp.blob();const bmp=await createImageBitmap(blob);this.imgCache.set(rawUrl,bmp);if(this.imgCache.size>40){const f=this.imgCache.keys().next().value;this.imgCache.delete(f);}}catch(_){}
  }
  _clearCanvas(){this.ctx.clearRect(0,0,this.canvas.width,this.canvas.height);}
  clearToBackground(){
    this._clearCanvas();
    const tgt=16/9,cr=this.canvas.width/this.canvas.height;
    let w,h,x,y;
    if(cr>tgt){h=this.canvas.height;w=h*tgt;x=(this.canvas.width-w)/2;y=0;}
    else{w=this.canvas.width;h=w/tgt;x=0;y=(this.canvas.height-h)/2;}
    this.ctx.fillStyle=this.bgColor;this.ctx.fillRect(x,y,w,h);
    // blank board: image rect and board rect are the same 16:9 rect
    this.slideLayout={x,y,w,h};this.boardLayout={x,y,w,h};this.currentUrl=null;
  }
}

// ════════════════════════════════════════════════════════════════════════════
// CANVAS RENDERER  (c9r4.js)  [DEBUG BUILD]
// ════════════════════════════════════════════════════════════════════════════
// Set window.UA_TRUE_COLORS = true to render the instructor's original stroke
// colours instead of the contrast-boosted ones (useful for comparing against
// a PDF export; can read poorly on a dark board).
// ════════════════════════════════════════════════════════════════════════════
class CanvasRenderer {
  constructor(canvas){
    this.canvas=canvas;this.ctx=canvas.getContext("2d");
    this._lastStrokeCount=-1;this._lastPointerX=null;this._lastPointerY=null;
    this._pointerPulse=0;this._slideLayout=null;this._forceRedraw=false;
    this._renderCount=0;   // DEBUG: total render passes
  }
  get width(){return this.canvas.width;}
  get height(){return this.canvas.height;}

  render(state,slideLayout,currentTimeMs){
    if(slideLayout&&slideLayout.w>0&&slideLayout.h>0)this._slideLayout=slideLayout;
    const strokes=state.getAllStrokes();
    const sc=strokes.length,ac=strokes.filter(s=>s.isActive).length;
    const pX=state.pointerVisible?state.pointerX:null,pY=state.pointerVisible?state.pointerY:null;
    const zk=`${state.zoom},${state.panX},${state.panY}`;
    const dirty=sc!==this._lastStrokeCount||ac!==(this._lastActiveCount||0)||pX!==this._lastPointerX||pY!==this._lastPointerY||zk!==this._lastZoomKey||this._forceRedraw;
    if(!dirty)return;

    this._renderCount++;
    this._lastStrokeCount=sc;this._lastActiveCount=ac;this._lastPointerX=pX;this._lastPointerY=pY;this._lastZoomKey=zk;this._forceRedraw=false;

    // ── DEBUG: render start ──────────────────────────────────────────────────
    const sl=this._slideLayout||{x:0,y:0,w:0,h:0};
    const dbgPrefix="[RENDER #"+this._renderCount+"] sid="+state.currentSid+" strokes="+sc;

    if(window._ua_dbg_verbose) {
      console.groupCollapsed(dbgPrefix);
      console.log("canvas:", this.width+"×"+this.height,
                  "board:", "x="+sl.x.toFixed(1)+" y="+sl.y.toFixed(1)+" w="+sl.w.toFixed(1)+" h="+sl.h.toFixed(1),
                  "zoom:", state.zoom, "pan:", state.panX, state.panY);
    }

    this.ctx.clearRect(0,0,this.width,this.height);
    this.ctx.save();this.ctx.imageSmoothingEnabled=true;this.ctx.imageSmoothingQuality="high";
    this._applyTransform(state.zoom,state.panX,state.panY);

    let renderedCount=0, skippedCount=0;
    for(const stroke of strokes){
      const result=this._drawStroke(stroke,currentTimeMs);
      if(result==="skipped") skippedCount++;
      else renderedCount++;
    }

    this.ctx.restore();
    if(state.pointerVisible&&state.pointerX!==null)this._drawPointer(state.pointerX,state.pointerY,state.zoom,state.panX,state.panY);
    this._pointerPulse+=0.15;

    if(window._ua_dbg_verbose) {
      console.log("rendered="+renderedCount+" skipped="+skippedCount);
      console.groupEnd();
    } else if(skippedCount>0) {
      console.warn("[RENDER] "+skippedCount+" strokes SKIPPED in render #"+this._renderCount+
                   " (sid="+state.currentSid+"). Call UA_DEBUG.verbose() then seek to reproduce.");
    }

    // Opt-in: surface annotation groups sitting outside the visible board
    // (see OffboardPanel below). Cheap no-op when the toggle is off.
    if(window.UA_SHOW_OFFBOARD && window.offboardPanel) window.offboardPanel.update(strokes);
  }

  invalidate(){this._forceRedraw=true;}

  _mapToSlide(nx,ny){
    const sl=this._slideLayout;
    if(sl&&sl.w>0&&sl.h>0)return{x:sl.x+nx*sl.w,y:sl.y+ny*sl.h};
    return{x:nx*this.width,y:ny*this.height};
  }

  _applyTransform(zoom,panX,panY){
    if(zoom===1&&panX===0&&panY===0)return;
    const cx=this.width/2,cy=this.height/2;
    this.ctx.translate(cx,cy);this.ctx.scale(zoom,zoom);this.ctx.translate(-cx+(-panX*this.width),-cy+(-panY*this.height));
  }

  _drawStroke(stroke,currentTimeMs){
    const pts=stroke.points;
    if(!pts||!pts.length){
      if(window._ua_dbg_verbose) console.warn("[RENDER SKIP] oid="+stroke.oid+" reason=no points");
      return "skipped";
    }
    const ctx=this.ctx,w=this.width;
    if(w<=0||this.height<=0){
      if(window._ua_dbg_verbose) console.warn("[RENDER SKIP] oid="+stroke.oid+" reason=canvas has zero dimension");
      return "skipped";
    }

    let color=window.UA_TRUE_COLORS?(stroke.color||"#FFFFFF"):this._boostColor(stroke.color||"#FFFFFF");
    const PW=750,sl=this._slideLayout,scX=(sl&&sl.w>0)?sl.w/PW:w/PW;
    const isTool=stroke.tool||"pen";
    ctx.save();

    if(isTool==="eraser"){ctx.globalCompositeOperation="destination-out";ctx.strokeStyle="rgba(0,0,0,1)";ctx.lineWidth=Math.max(2,(stroke.size+4)*scX);}
    else if(isTool==="highlighter"||isTool==="marker-h"){
      ctx.globalCompositeOperation="source-over";
      ctx.strokeStyle=this._withAlpha(color,0.35);
      ctx.lineWidth=Math.max(2,stroke.size*10*scX);
    } else if(isTool==="marker"){
      const dpr=window.devicePixelRatio||1;
      ctx.globalCompositeOperation="source-over";ctx.strokeStyle=this._withAlpha(color,1);
      ctx.lineWidth=Math.max(1.2*dpr,stroke.size*1.8*scX);ctx.shadowColor="transparent";ctx.shadowBlur=0;
    } else {
      const dpr=window.devicePixelRatio||1;
      ctx.globalCompositeOperation="source-over";ctx.strokeStyle=color;
      ctx.lineWidth=Math.max(2.2*dpr,stroke.size*3.2*scX);ctx.shadowColor="transparent";ctx.shadowBlur=0;
    }

    ctx.lineCap="round";
    const isShape=isTool==="rectangle"||isTool==="arrow"||isTool==="line";
    ctx.lineJoin=isShape?"miter":"round";

    const vp=pts.filter(p=>{if(!p)return false;const x=parseFloat(p.x??0),y=parseFloat(p.y??0);return!isNaN(x)&&!isNaN(y)&&isFinite(x)&&isFinite(y);});
    if(!vp.length){
      if(window._ua_dbg_verbose) console.warn("[RENDER SKIP] oid="+stroke.oid+" reason=all points filtered NaN/Inf");
      ctx.restore();return "skipped";
    }

    // DEBUG: check if all mapped points land outside the canvas rect
    if(window._ua_dbg_verbose) {
      const mapped=vp.map(p=>this._mapToSlide(p.x,p.y));
      const anyVisible=mapped.some(m=>m.x>=0&&m.x<=this.width&&m.y>=0&&m.y<=this.height);
      if(!anyVisible) {
        console.warn("[OUTSIDE CANVAS] oid="+stroke.oid+" tool="+isTool+
          " ALL "+mapped.length+" points outside canvas "+this.width+"×"+this.height);
        console.warn("  raw pts x range: ["+Math.min(...vp.map(p=>p.x)).toFixed(3)+","+
          Math.max(...vp.map(p=>p.x)).toFixed(3)+"]"+
          " y: ["+Math.min(...vp.map(p=>p.y)).toFixed(3)+","+Math.max(...vp.map(p=>p.y)).toFixed(3)+"]");
        console.warn("  slide layout:", this._slideLayout);
      }
    }

    if(isTool==="rectangle"&&vp.length>=2){
      // The rectangle tool records the whole drag path, not just the two
      // corners — any hand tremor mid-drag pushes a point outside the
      // intended corner, and scanning min/max across every point bakes
      // that wobble into the rendered edge. Two rectangles drawn side by
      // side (e.g. grid dividers) then get visibly mismatched shared
      // edges — a "doubled line" that isn't in the source recording.
      // The drag start/end points are the actual corners; use those.
      const rs=this._mapToSlide(vp[0].x,vp[0].y),re=this._mapToSlide(vp[vp.length-1].x,vp[vp.length-1].y);
      const mnX=Math.min(rs.x,re.x),mnY=Math.min(rs.y,re.y),mxX=Math.max(rs.x,re.x),mxY=Math.max(rs.y,re.y);
      ctx.beginPath();ctx.rect(mnX,mnY,mxX-mnX,mxY-mnY);ctx.stroke();
    } else if((isTool==="circle"||isTool==="ellipse"||isTool==="oval")&&vp.length>=2){
      // Same wobble bug as the rectangle fix above: the tool records the
      // whole drag path, so scanning min/max across every point bakes hand
      // tremor into the rendered ellipse. Use the drag start/end corners.
      const cs=this._mapToSlide(vp[0].x,vp[0].y),ce=this._mapToSlide(vp[vp.length-1].x,vp[vp.length-1].y);
      const mnX=Math.min(cs.x,ce.x),mnY=Math.min(cs.y,ce.y),mxX=Math.max(cs.x,ce.x),mxY=Math.max(cs.y,ce.y);
      const rw=mxX-mnX,rh=mxY-mnY;
      ctx.beginPath();ctx.ellipse(mnX+rw/2,mnY+rh/2,Math.max(.1,rw/2),Math.max(.1,rh/2),0,0,2*Math.PI);ctx.stroke();
    } else if((isTool==="line"||isTool==="dashed-line"||isTool==="arrow")&&vp.length>=2){
      const s=this._mapToSlide(vp[0].x,vp[0].y),e=this._mapToSlide(vp[vp.length-1].x,vp[vp.length-1].y);
      ctx.beginPath();ctx.moveTo(s.x,s.y);ctx.lineTo(e.x,e.y);
      if(isTool==="dashed-line"){ctx.save();ctx.setLineDash([10*scX,10*scX]);ctx.stroke();ctx.restore();}else ctx.stroke();
      if(isTool==="arrow"){const hs=Math.max(8,stroke.size*3*scX),ang=Math.atan2(e.y-s.y,e.x-s.x)+Math.PI,ad=Math.PI/4;ctx.beginPath();ctx.moveTo(e.x,e.y);ctx.lineTo(e.x+hs*Math.cos(ang-ad),e.y+hs*Math.sin(ang-ad));ctx.moveTo(e.x,e.y);ctx.lineTo(e.x+hs*Math.cos(ang+ad),e.y+hs*Math.sin(ang+ad));ctx.stroke();}
    } else {
      let pp=vp;
      if(vp.length===1)pp=[vp[0],vp[0],vp[0]];else if(vp.length===2)pp=[vp[0],vp[0],vp[1]];
      ctx.beginPath();const p0=this._mapToSlide(pp[0].x,pp[0].y);ctx.moveTo(p0.x,p0.y);
      for(let i=1;i<pp.length-1;i++){const pi=this._mapToSlide(pp[i].x,pp[i].y),pi1=this._mapToSlide(pp[i+1].x,pp[i+1].y);ctx.quadraticCurveTo(pi.x,pi.y,(pi.x+pi1.x)/2,(pi.y+pi1.y)/2);}
      const pl=this._mapToSlide(pp[pp.length-1].x,pp[pp.length-1].y);ctx.lineTo(pl.x,pl.y);ctx.stroke();
    }
    ctx.restore();

    if(window._ua_dbg_verbose) {
      console.log("[RENDER OBJECT] oid="+stroke.oid+" tool="+isTool+" color="+stroke.color+" pts="+vp.length);
    }
    return "ok";
  }

  _drawPointer(nx,ny,zoom,panX,panY){
    const ctx=this.ctx,w=this.width,h=this.height;
    const m=this._mapToSlide(nx,ny);let px=m.x,py=m.y;
    if(zoom!==1||panX!==0||panY!==0){const cx=w/2,cy=h/2;px=(px-cx)*zoom+cx+(-panX*w*zoom);py=(py-cy)*zoom+cy+(-panY*h*zoom);}
    ctx.save();
    const p1=Math.sin(this._pointerPulse)*.3+.7,p2=Math.sin(this._pointerPulse-1)*.3+.7;
    const g1=ctx.createRadialGradient(px,py,0,px,py,18);g1.addColorStop(0,`rgba(255,50,50,${.2*p1})`);g1.addColorStop(1,"rgba(255,50,50,0)");
    ctx.fillStyle=g1;ctx.beginPath();ctx.arc(px,py,18,0,Math.PI*2);ctx.fill();
    const g2=ctx.createRadialGradient(px,py,0,px,py,10);g2.addColorStop(0,`rgba(255,50,50,${.4*p2})`);g2.addColorStop(1,"rgba(255,50,50,0)");
    ctx.fillStyle=g2;ctx.beginPath();ctx.arc(px,py,10,0,Math.PI*2);ctx.fill();
    ctx.fillStyle=`rgba(255,60,60,${p1})`;ctx.beginPath();ctx.arc(px,py,4,0,Math.PI*2);ctx.fill();
    ctx.restore();
  }
  _withAlpha(c,a){if(!c)return c;if(c.startsWith("hsl"))return c.replace("hsl","hsla").replace(")",`,${a})`);try{let h=c.startsWith("#")?c.slice(1):c;if(h.length===8)h=h.slice(2);if(h.length===3)h=h.split("").map(x=>x+x).join("");return`rgba(${parseInt(h.slice(0,2),16)},${parseInt(h.slice(2,4),16)},${parseInt(h.slice(4,6),16)},${a})`;}catch{return c;}}
  _boostColor(hex){
    if(!hex||typeof hex!=="string")return"#ffffff";let cl=hex.trim();
    if(!cl.startsWith("#")){if(cl.startsWith("rgb")||cl.startsWith("hsl"))return cl;cl="#"+cl;}
    try{let c=cl.slice(1);if(c.length===8)c=c.slice(2);if(c.length===3)c=c.split("").map(x=>x+x).join("");if(c.length!==6)return"#ffffff";
    const r=parseInt(c.slice(0,2),16),g=parseInt(c.slice(2,4),16),b=parseInt(c.slice(4,6),16);
    const[h,s,l]=this._rgbToHsl(r,g,b);
    if(l>.75&&s<.3)return"#ffffff";if(l<.12)return"#e0e0e0";
    const[nr,ng,nb]=this._hslToRgb(h,1,Math.max(.55,Math.min(.75,l)));
    return`#${nr.toString(16).padStart(2,"0")}${ng.toString(16).padStart(2,"0")}${nb.toString(16).padStart(2,"0")}`;}catch{return"#ffffff";}
  }
  _rgbToHsl(r,g,b){r/=255;g/=255;b/=255;const mx=Math.max(r,g,b),mn=Math.min(r,g,b);let h,s,l=(mx+mn)/2;if(mx===mn){h=s=0;}else{const d=mx-mn;s=l>.5?d/(2-mx-mn):d/(mx+mn);switch(mx){case r:h=((g-b)/d+(g<b?6:0))/6;break;case g:h=((b-r)/d+2)/6;break;default:h=((r-g)/d+4)/6;}}return[h,s,l];}
  _hslToRgb(h,s,l){if(s===0){const v=Math.round(l*255);return[v,v,v];}const q=l<.5?l*(1+s):l+s-l*s,p=2*l-q;const h2r=(p,q,t)=>{if(t<0)t+=1;if(t>1)t-=1;if(t<1/6)return p+(q-p)*6*t;if(t<.5)return q;if(t<2/3)return p+(q-p)*(2/3-t)*6;return p;};return[Math.round(h2r(p,q,h+1/3)*255),Math.round(h2r(p,q,h)*255),Math.round(h2r(p,q,h-1/3)*255)];}
  clearAll(){this.ctx.clearRect(0,0,this.width,this.height);}
}

// ════════════════════════════════════════════════════════════════════════════
// OFFBOARD PANEL  (opt-in)
// ════════════════════════════════════════════════════════════════════════════
// Annotation coordinates are normalised against the 16:9 board (see the note
// at the top of this file), but nothing stops a stroke — or a whole pasted
// copy of one — from living outside [0,1]. Those never render on the main
// canvas (the canvas simply clips them), so by default they are invisible
// and inert: nothing here changes what the normal render shows.
//
// When window.UA_SHOW_OFFBOARD is true, CanvasRenderer.render() calls
// OffboardPanel.update() with the current stroke list. This class:
//   1. groups strokes into spatial clusters (expand each stroke's bbox by a
//      small pad and union-find anything that overlaps — this naturally
//      keeps a pasted grid's border + dividers + marks together without any
//      assumption about tool types or a fixed shape),
//   2. keeps only clusters whose bbox never touches the padded board square
//      (i.e. truly off-board, not just a stroke that slightly overhangs a
//      visible edge),
//   3. draws each such cluster into its own small thumbnail canvas, mapped
//      from that cluster's own bounding box (not the board's), so scratch
//      content parked far outside the board is still legible at thumbnail
//      size.
// This is purely a read-only inspection view — it never feeds back into
// StateManager, the seek bar, or the main render.
// ════════════════════════════════════════════════════════════════════════════
class OffboardPanel {
  constructor(trackEl){
    this.track=trackEl;
    this._lastSid=null;this._lastSig=null;
  }

  _bbox(stroke){
    let mnX=Infinity,mnY=Infinity,mxX=-Infinity,mxY=-Infinity;
    for(const p of stroke.points){
      const x=parseFloat(p.x??0),y=parseFloat(p.y??0);
      if(!isFinite(x)||!isFinite(y))continue;
      if(x<mnX)mnX=x;if(x>mxX)mxX=x;if(y<mnY)mnY=y;if(y>mxY)mxY=y;
    }
    if(mnX===Infinity)return null;
    return{mnX,mnY,mxX,mxY};
  }

  _cluster(strokes,pad){
    const items=[];
    for(const s of strokes){const b=this._bbox(s);if(b)items.push({stroke:s,b});}
    const n=items.length,parent=Array.from({length:n},(_,i)=>i);
    const find=x=>{while(parent[x]!==x){parent[x]=parent[parent[x]];x=parent[x];}return x;};
    const union=(a,c)=>{const ra=find(a),rc=find(c);if(ra!==rc)parent[ra]=rc;};
    const overlaps=(a,b)=>!(a.mxX+pad<b.mnX||b.mxX+pad<a.mnX||a.mxY+pad<b.mnY||b.mxY+pad<a.mnY);
    for(let i=0;i<n;i++)for(let j=i+1;j<n;j++)if(overlaps(items[i].b,items[j].b))union(i,j);
    const groups=new Map();
    for(let i=0;i<n;i++){const r=find(i);if(!groups.has(r))groups.set(r,[]);groups.get(r).push(items[i]);}
    return[...groups.values()].map(grp=>{
      let mnX=Infinity,mnY=Infinity,mxX=-Infinity,mxY=-Infinity;
      for(const it of grp){if(it.b.mnX<mnX)mnX=it.b.mnX;if(it.b.mxX>mxX)mxX=it.b.mxX;if(it.b.mnY<mnY)mnY=it.b.mnY;if(it.b.mxY>mxY)mxY=it.b.mxY;}
      return{strokes:grp.map(it=>it.stroke),bbox:{mnX,mnY,mxX,mxY}};
    });
  }

  // "Off-board" = the cluster's bbox never touches the board square, even
  // with a margin. A stroke that merely overhangs a visible edge (its
  // bbox still overlaps [0,1]²) is left alone — it's part of what's shown.
  _isOffboard(bbox,margin){
    return bbox.mxX<-margin||bbox.mnX>1+margin||bbox.mxY<-margin||bbox.mnY>1+margin;
  }

  update(strokes){
    if(!this.track)return;
    // Cheap change-detection so we don't rebuild thumbnails every frame.
    const sig=strokes.length+":"+(strokes.length?strokes[strokes.length-1].oid+strokes[strokes.length-1].points.length:"");
    if(sig===this._lastSig)return;
    this._lastSig=sig;

    const clusters=this._cluster(strokes,0.04)
      .filter(c=>this._isOffboard(c.bbox,0.05))
      .sort((a,b)=>a.bbox.mnY-b.bbox.mnY||a.bbox.mnX-b.bbox.mnX);

    this.track.innerHTML="";
    clusters.forEach((c,i)=>this.track.appendChild(this._buildCard(c,i)));
  }

  _buildCard(cluster,idx){
    const card=document.createElement("div");card.className="offboard-card";
    const canvas=document.createElement("canvas");canvas.width=300;canvas.height=180;
    card.appendChild(canvas);
    const label=document.createElement("div");label.className="oc-label";
    const byTool={};let markCount=0;
    for(const s of cluster.strokes){
      byTool[s.tool]=(byTool[s.tool]||0)+1;
      if(s.tool!=="rectangle"&&s.tool!=="line"&&s.tool!=="eraser")markCount++;
    }
    const isBlank=markCount===0;
    if(isBlank)card.classList.add("oc-empty");
    const parts=Object.keys(byTool).map(t=>byTool[t]+" "+t+(byTool[t]>1?"s":""));
    label.innerHTML=`<span>${isBlank?"blank":parts.join(", ")}</span><span class="oc-idx">#${idx+1}</span>`;
    card.appendChild(label);
    this._paintThumb(canvas,cluster);
    return card;
  }

  _paintThumb(canvas,cluster){
    const ctx=canvas.getContext("2d");
    ctx.clearRect(0,0,canvas.width,canvas.height);
    ctx.fillStyle="#202022";ctx.fillRect(0,0,canvas.width,canvas.height);
    const b=cluster.bbox,pad=Math.max(0.01,(b.mxX-b.mnX)*0.08,(b.mxY-b.mnY)*0.08);
    const bx=b.mnX-pad,by=b.mnY-pad,bw=(b.mxX-b.mnX)+pad*2,bh=(b.mxY-b.mnY)+pad*2;
    const scale=Math.min(canvas.width/Math.max(bw,1e-6),canvas.height/Math.max(bh,1e-6));
    const ox=(canvas.width-bw*scale)/2,oy=(canvas.height-bh*scale)/2;
    const map=(nx,ny)=>({x:ox+(nx-bx)*scale,y:oy+(ny-by)*scale});

    for(const s of cluster.strokes){
      if(s.tool==="eraser")continue; // an undo/erase leaves no visible mark
      const pts=s.points.filter(p=>isFinite(p.x)&&isFinite(p.y));
      if(!pts.length)continue;
      ctx.save();
      ctx.lineCap="round";ctx.lineJoin="round";
      ctx.strokeStyle=s.color||"#EAEAEA";
      ctx.lineWidth=Math.max(1,(s.size||1)*1.6);
      if(s.tool==="highlighter"||s.tool==="marker-h"){ctx.globalAlpha=0.35;ctx.lineWidth=Math.max(2,(s.size||1)*4);}
      if(s.tool==="rectangle"&&pts.length>=2){
        const p0=map(pts[0].x,pts[0].y),p1=map(pts[pts.length-1].x,pts[pts.length-1].y);
        ctx.strokeRect(Math.min(p0.x,p1.x),Math.min(p0.y,p1.y),Math.abs(p1.x-p0.x),Math.abs(p1.y-p0.y));
      } else if((s.tool==="line"||s.tool==="dashed-line"||s.tool==="arrow")&&pts.length>=2){
        const p0=map(pts[0].x,pts[0].y),p1=map(pts[pts.length-1].x,pts[pts.length-1].y);
        ctx.beginPath();ctx.moveTo(p0.x,p0.y);ctx.lineTo(p1.x,p1.y);ctx.stroke();
      } else if((s.tool==="circle"||s.tool==="ellipse"||s.tool==="oval")&&pts.length>=2){
        const p0=map(pts[0].x,pts[0].y),p1=map(pts[pts.length-1].x,pts[pts.length-1].y);
        const mnX=Math.min(p0.x,p1.x),mnY=Math.min(p0.y,p1.y),mxX=Math.max(p0.x,p1.x),mxY=Math.max(p0.y,p1.y);
        ctx.beginPath();ctx.ellipse((mnX+mxX)/2,(mnY+mxY)/2,Math.max(.1,(mxX-mnX)/2),Math.max(.1,(mxY-mnY)/2),0,0,2*Math.PI);ctx.stroke();
      } else {
        ctx.beginPath();
        const p0=map(pts[0].x,pts[0].y);ctx.moveTo(p0.x,p0.y);
        for(let i=1;i<pts.length;i++){const p=map(pts[i].x,pts[i].y);ctx.lineTo(p.x,p.y);}
        ctx.stroke();
      }
      ctx.restore();
    }
  }
}

// _ua_dbg_verbose is set to true/false by UA_DEBUG.verbose() / UA_DEBUG.quiet()
// in copy-fix-debug.js (via window._ua_dbg_verbose).
// It is checked by CanvasRenderer._drawStroke and .render() above.