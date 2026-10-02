/**
 * Visionneuse de plan avec annotations (crayon / texte) — page de validation d'un plan.
 * Rendu du PDF par PDF.js ; une couche canvas transparente par page porte les annotations.
 * - aucune annotation : le plan peut être validé
 * - annotations : le plan ne peut plus être validé, on envoie les modifications (PNG transparent par page)
 */
(function () {
    'use strict';
    const cfg = window.ispagPlanCfg;
    if (!cfg || !window.pdfjsLib) return;
    pdfjsLib.GlobalWorkerOptions.workerSrc = cfg.workerSrc;

    const pagesEl = document.getElementById('plan-pages');
    const btnValidate = document.getElementById('btn-validate-plan');
    const btnChanges = document.getElementById('btn-request-changes');
    const colorInput = document.getElementById('plan-color');
    const widthSelect = document.getElementById('plan-width');
    const hintEl = document.getElementById('plan-hint');

    let tool = 'pen';
    const pages = []; // { overlay, ctx, items: [] }
    const history = []; // index des pages, dans l'ordre des ajouts (annuler)

    // ---------------------------------------------------------------- état
    function hasAnnotations() { return pages.some(function (p) { return p.items.length > 0; }); }

    function refreshButtons() {
        const modified = hasAnnotations();
        btnValidate.disabled = modified;
        btnValidate.title = modified ? cfg.i18n.validateDisabled : '';
        btnChanges.disabled = !modified;
        hintEl.textContent = modified ? cfg.i18n.hintModified : cfg.i18n.hintClean;
    }

    // ---------------------------------------------------------------- dessin
    function redraw(page) {
        const ctx = page.ctx;
        ctx.clearRect(0, 0, page.overlay.width, page.overlay.height);
        page.items.forEach(function (it) {
            if (it.type === 'pen') {
                ctx.strokeStyle = it.color; ctx.lineWidth = it.width; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
                ctx.beginPath();
                it.points.forEach(function (pt, i) { if (i === 0) ctx.moveTo(pt[0], pt[1]); else ctx.lineTo(pt[0], pt[1]); });
                if (it.points.length === 1) ctx.lineTo(it.points[0][0] + 0.01, it.points[0][1]);
                ctx.stroke();
            } else if (it.type === 'text') {
                ctx.fillStyle = it.color;
                ctx.font = 'bold ' + it.size + 'px Arial, sans-serif';
                ctx.textBaseline = 'top';
                it.text.split('\n').forEach(function (line, i) { ctx.fillText(line, it.x, it.y + i * it.size * 1.2); });
            }
        });
    }

    function pos(page, e) {
        const r = page.overlay.getBoundingClientRect();
        return [(e.clientX - r.left) * (page.overlay.width / r.width), (e.clientY - r.top) * (page.overlay.height / r.height)];
    }

    function bindOverlay(page, idx, wrap) {
        const overlay = page.overlay;
        let current = null;

        overlay.addEventListener('pointerdown', function (e) {
            if (tool === 'pen') {
                e.preventDefault();
                overlay.setPointerCapture(e.pointerId);
                current = { type: 'pen', color: colorInput.value, width: parseInt(widthSelect.value, 10), points: [pos(page, e)] };
                page.items.push(current);
                redraw(page);
            } else if (tool === 'text') {
                e.preventDefault();
                addTextInput(page, idx, wrap, e);
            }
        });
        overlay.addEventListener('pointermove', function (e) {
            if (!current) return;
            current.points.push(pos(page, e));
            redraw(page);
        });
        function end() {
            if (!current) return;
            current = null;
            history.push(idx);
            refreshButtons();
        }
        overlay.addEventListener('pointerup', end);
        overlay.addEventListener('pointercancel', end);
    }

    function addTextInput(page, idx, wrap, e) {
        const p = pos(page, e);
        const r = page.overlay.getBoundingClientRect();
        const scale = r.width / page.overlay.width; // canvas px -> écran
        const size = Math.max(14, parseInt(widthSelect.value, 10) * 5);
        const input = document.createElement('textarea');
        input.rows = 1;
        input.className = 'plan-text-input';
        input.style.left = ((e.clientX - r.left)) + 'px';
        input.style.top = ((e.clientY - r.top)) + 'px';
        input.style.color = colorInput.value;
        input.style.fontSize = (size * scale) + 'px';
        wrap.appendChild(input);
        input.focus();

        let done = false;
        function commit() {
            if (done) return;
            done = true;
            const text = input.value.replace(/\s+$/, '');
            input.remove();
            if (!text) return;
            page.items.push({ type: 'text', color: colorInput.value, size: size, x: p[0], y: p[1], text: text });
            history.push(idx);
            redraw(page);
            refreshButtons();
        }
        input.addEventListener('blur', commit);
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') { input.value = ''; input.blur(); }
            else if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); input.blur(); }
        });
    }

    // ---------------------------------------------------------------- outils
    document.querySelectorAll('[data-tool]').forEach(function (b) {
        b.addEventListener('click', function () {
            tool = b.dataset.tool;
            document.querySelectorAll('[data-tool]').forEach(function (x) { x.classList.toggle('is-active', x === b); });
            pagesEl.dataset.tool = tool;
        });
    });
    document.getElementById('plan-undo').addEventListener('click', function () {
        const idx = history.pop();
        if (idx === undefined) return;
        pages[idx].items.pop();
        redraw(pages[idx]);
        refreshButtons();
    });
    document.getElementById('plan-clear').addEventListener('click', function () {
        if (!hasAnnotations() || !window.confirm(cfg.i18n.confirmClear)) return;
        pages.forEach(function (p) { p.items = []; redraw(p); });
        history.length = 0;
        refreshButtons();
    });

    // ---------------------------------------------------------------- envoi
    function post(formData, btn, label) {
        btn.disabled = true;
        btn.textContent = cfg.i18n.busy + '...';
        return fetch(cfg.ajaxUrl, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    if (window.opener) { window.opener.location.reload(); window.close(); } else { location.reload(); }
                } else {
                    window.alert('Error: ' + res.data);
                    btn.textContent = label;
                    refreshButtons();
                }
            })
            .catch(function () { window.alert('Network error'); btn.textContent = label; refreshButtons(); });
    }

    btnValidate.addEventListener('click', function () {
        if (hasAnnotations() || !window.confirm(cfg.i18n.confirmValidate)) return;
        const fd = new FormData();
        fd.append('action', 'ispag_validate_pdf_plan');
        fd.append('nonce', cfg.nonce);
        fd.append('drawing_id', cfg.drawingId);
        fd.append('article_id', cfg.articleId);
        post(fd, btnValidate, cfg.i18n.validateLabel);
    });

    btnChanges.addEventListener('click', function () {
        if (!hasAnnotations() || !window.confirm(cfg.i18n.confirmChanges)) return;
        btnChanges.disabled = true;
        const fd = new FormData();
        fd.append('action', 'ispag_plan_request_changes');
        fd.append('nonce', cfg.nonce);
        fd.append('drawing_id', cfg.drawingId);
        fd.append('article_id', cfg.articleId);
        const jobs = pages.map(function (p, i) {
            if (!p.items.length) return Promise.resolve();
            return new Promise(function (resolve) {
                p.overlay.toBlob(function (blob) { fd.append('overlay_' + (i + 1), blob, 'overlay_' + (i + 1) + '.png'); resolve(); }, 'image/png');
            });
        });
        Promise.all(jobs).then(function () { post(fd, btnChanges, cfg.i18n.changesLabel); });
    });

    // ---------------------------------------------------------------- rendu PDF
    pdfjsLib.getDocument(cfg.pdfUrl).promise.then(function (pdf) {
        let chain = Promise.resolve();
        for (let n = 1; n <= pdf.numPages; n++) {
            chain = chain.then(function () { return renderPage(pdf, n); });
        }
        return chain;
    }).catch(function (err) {
        pagesEl.textContent = 'PDF error: ' + (err && err.message ? err.message : err);
    });

    function renderPage(pdf, n) {
        return pdf.getPage(n).then(function (pg) {
            const base = pg.getViewport({ scale: 1 });
            const targetWidth = Math.min(pagesEl.clientWidth || 900, 1100);
            const scale = (targetWidth / base.width) * (window.devicePixelRatio > 1 ? 1.5 : 1);
            const vp = pg.getViewport({ scale: scale });

            const wrap = document.createElement('div');
            wrap.className = 'plan-page';
            wrap.style.aspectRatio = base.width + ' / ' + base.height;

            const canvas = document.createElement('canvas');
            canvas.width = vp.width; canvas.height = vp.height;
            const overlay = document.createElement('canvas');
            overlay.width = vp.width; overlay.height = vp.height;
            overlay.className = 'plan-overlay';
            wrap.appendChild(canvas);
            wrap.appendChild(overlay);
            pagesEl.appendChild(wrap);

            const page = { overlay: overlay, ctx: overlay.getContext('2d'), items: [] };
            pages.push(page);
            bindOverlay(page, pages.length - 1, wrap);

            return pg.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
        });
    }

    refreshButtons();
})();
