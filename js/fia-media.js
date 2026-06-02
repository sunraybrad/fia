/**
 * fia-media.js — Shared lightbox with zoom/pan for photo and video grids.
 *
 * Expects in the page:
 *   - Elements with class  .media-thumb  carrying data-src, data-type, data-caption
 *   - A Bootstrap 5 modal  #mediaModal  with inner elements:
 *       #media-modal-body, #media-modal-caption, #media-modal-counter,
 *       #media-prev, #media-next, #media-zoom-in, #media-zoom-out, #media-zoom-reset, #media-zoom-level
 */

(function () {
    'use strict';

    const thumbs = Array.from(document.querySelectorAll('.media-thumb'));
    if (!thumbs.length) return;

    const modalEl   = document.getElementById('mediaModal');
    if (!modalEl) return;

    const body      = document.getElementById('media-modal-body');
    const caption   = document.getElementById('media-modal-caption');
    const counter   = document.getElementById('media-modal-counter');
    const btnPrev   = document.getElementById('media-prev');
    const btnNext   = document.getElementById('media-next');
    const btnZoomIn = document.getElementById('media-zoom-in');
    const btnZoomOut= document.getElementById('media-zoom-out');
    const btnReset  = document.getElementById('media-zoom-reset');
    const zoomLabel = document.getElementById('media-zoom-level');

    let currentIndex = 0;
    let _modal       = null;

    // ── Zoom / pan state ─────────────────────────────────────────────────

    let scale  = 1;
    let tx     = 0;    // translateX px
    let ty     = 0;    // translateY px
    let isDragging = false;
    let dragStart  = { x: 0, y: 0 };
    let txStart    = 0;
    let tyStart    = 0;

    const MIN_SCALE = 1;
    const MAX_SCALE = 8;
    const STEP      = 0.4;

    function getModal() {
        if (!_modal) _modal = new bootstrap.Modal(modalEl);
        return _modal;
    }

    function applyTransform(el) {
        if (!el) return;
        el.style.transform       = `translate(${tx}px, ${ty}px) scale(${scale})`;
        el.style.transformOrigin = '50% 50%';
        el.style.transition      = isDragging ? 'none' : 'transform 0.15s ease';
    }

    function clampTranslation(el) {
        if (!el || scale <= 1) { tx = 0; ty = 0; return; }
        // Allow panning up to half the excess size
        const rect = el.getBoundingClientRect();
        const containerW = body.clientWidth;
        const containerH = body.clientHeight;
        const maxTx = Math.max(0, (rect.width  - containerW) / 2);
        const maxTy = Math.max(0, (rect.height - containerH) / 2);
        tx = Math.max(-maxTx, Math.min(maxTx, tx));
        ty = Math.max(-maxTy, Math.min(maxTy, ty));
    }

    function setScale(newScale, el) {
        scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, newScale));
        if (scale === MIN_SCALE) { tx = 0; ty = 0; }
        if (zoomLabel) zoomLabel.textContent = Math.round(scale * 100) + '%';
        if (btnReset)  btnReset.disabled  = (scale === MIN_SCALE && tx === 0 && ty === 0);
        if (btnZoomIn) btnZoomIn.disabled  = (scale >= MAX_SCALE);
        if (btnZoomOut)btnZoomOut.disabled = (scale <= MIN_SCALE);
        body.style.cursor = scale > 1 ? 'grab' : 'zoom-in';
        applyTransform(el);
    }

    function resetZoom(el) {
        scale = 1; tx = 0; ty = 0;
        setScale(1, el);
        applyTransform(el);
    }

    function getMediaEl() {
        return body.querySelector('img, video');
    }

    // ── Load media into modal ─────────────────────────────────────────────

    function showMedia(index) {
        currentIndex = index;
        const thumb   = thumbs[index];
        const src     = thumb.dataset.src;
        const type    = thumb.dataset.type;
        const cap     = thumb.dataset.caption || '';

        // Clear & reset zoom
        body.innerHTML = '';
        scale = 1; tx = 0; ty = 0;
        if (zoomLabel) zoomLabel.textContent = '100%';
        if (btnZoomIn)  btnZoomIn.disabled  = false;
        if (btnZoomOut) btnZoomOut.disabled = true;
        if (btnReset)   btnReset.disabled   = true;
        body.style.cursor = 'zoom-in';

        let el;
        if (type === 'video') {
            el = document.createElement('video');
            el.src      = src;
            el.controls = true;
            el.autoplay = true;
            el.style.cssText =
                'max-width:100%;max-height:75vh;display:block;margin:0 auto;';
            body.style.cursor = 'default';
        } else {
            el = document.createElement('img');
            el.src = src;
            el.alt = cap;
            el.style.cssText =
                'max-width:100%;max-height:75vh;display:block;margin:0 auto;user-select:none;';
            el.onerror = () => { el.onerror = null; el.src = '/images/photo_missing.png'; };
            attachZoomEvents(el);
        }

        body.appendChild(el);

        if (caption) caption.textContent = cap;
        if (counter) counter.textContent = (index + 1) + ' / ' + thumbs.length;
        if (btnPrev) btnPrev.disabled = (index === 0);
        if (btnNext) btnNext.disabled = (index === thumbs.length - 1);
    }

    // ── Zoom events (images only) ─────────────────────────────────────────

    function attachZoomEvents(img) {

        // Scroll wheel zoom — centered on cursor position
        body.addEventListener('wheel', function onWheel(e) {
            e.preventDefault();
            const rect   = img.getBoundingClientRect();
            const delta  = e.deltaY < 0 ? STEP : -STEP;
            const newScale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale + delta));

            // Shift origin toward cursor
            if (newScale !== scale) {
                const cursorX = e.clientX - rect.left - rect.width  / 2;
                const cursorY = e.clientY - rect.top  - rect.height / 2;
                tx -= cursorX * (newScale / scale - 1);
                ty -= cursorY * (newScale / scale - 1);
            }

            setScale(newScale, img);
            clampTranslation(img);
            applyTransform(img);
        }, { passive: false });

        // Double-click zoom toggle
        img.addEventListener('dblclick', function () {
            if (scale > 1) {
                resetZoom(img);
            } else {
                tx = 0; ty = 0;
                setScale(2.5, img);
                applyTransform(img);
            }
        });

        // Click-drag to pan
        img.addEventListener('mousedown', function (e) {
            if (scale <= 1) return;
            e.preventDefault();
            isDragging = true;
            dragStart  = { x: e.clientX, y: e.clientY };
            txStart    = tx;
            tyStart    = ty;
            body.style.cursor = 'grabbing';
        });

        window.addEventListener('mousemove', function (e) {
            if (!isDragging) return;
            tx = txStart + (e.clientX - dragStart.x);
            ty = tyStart + (e.clientY - dragStart.y);
            clampTranslation(getMediaEl());
            applyTransform(getMediaEl());
        });

        window.addEventListener('mouseup', function () {
            if (!isDragging) return;
            isDragging = false;
            body.style.cursor = scale > 1 ? 'grab' : 'zoom-in';
        });

        // Touch pinch-to-zoom
        let lastTouchDist = null;
        body.addEventListener('touchstart', function (e) {
            if (e.touches.length === 2) {
                lastTouchDist = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
            }
        }, { passive: true });

        body.addEventListener('touchmove', function (e) {
            if (e.touches.length === 2 && lastTouchDist) {
                e.preventDefault();
                const dist = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
                const newScale = Math.min(MAX_SCALE, Math.max(MIN_SCALE,
                    scale * (dist / lastTouchDist)));
                lastTouchDist = dist;
                setScale(newScale, img);
                clampTranslation(img);
                applyTransform(img);
            }
        }, { passive: false });

        body.addEventListener('touchend', () => { lastTouchDist = null; });
    }

    // ── Button controls ───────────────────────────────────────────────────

    btnZoomIn?.addEventListener('click', () => {
        const el = getMediaEl();
        tx = 0; ty = 0;
        setScale(scale + STEP, el);
        applyTransform(el);
    });

    btnZoomOut?.addEventListener('click', () => {
        const el = getMediaEl();
        setScale(scale - STEP, el);
        clampTranslation(el);
        applyTransform(el);
    });

    btnReset?.addEventListener('click', () => resetZoom(getMediaEl()));

    btnPrev?.addEventListener('click', () => {
        if (currentIndex > 0) showMedia(currentIndex - 1);
    });

    btnNext?.addEventListener('click', () => {
        if (currentIndex < thumbs.length - 1) showMedia(currentIndex + 1);
    });

    // Keyboard
    modalEl.addEventListener('keydown', e => {
        if (e.key === 'ArrowLeft'  && currentIndex > 0)              showMedia(currentIndex - 1);
        if (e.key === 'ArrowRight' && currentIndex < thumbs.length - 1) showMedia(currentIndex + 1);
        if (e.key === '+' || e.key === '=') btnZoomIn?.click();
        if (e.key === '-')                  btnZoomOut?.click();
        if (e.key === '0')                  btnReset?.click();
    });

    // Clean up on close
    modalEl.addEventListener('hide.bs.modal', () => {
        body.innerHTML = '';
        resetZoom(null);
    });

    // ── Open on thumb click ───────────────────────────────────────────────

    thumbs.forEach((el, i) => {
        el.addEventListener('click', () => {
            showMedia(i);
            getModal().show();
        });
    });

})();
