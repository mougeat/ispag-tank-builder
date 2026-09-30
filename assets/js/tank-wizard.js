/**
 * Création d'un réservoir sur mesure, étape par étape.
 *
 * S'active uniquement sur le formulaire d'un NOUVEL article de type réservoir (pas de data-article-id) :
 *   1. Design  2. Dimensions  3. Welding  4. Fittings  5. Insulation  6. Details & save
 * Le réservoir est créé à la fin de l'étape 2 (avec le circuit d'enregistrement habituel du formulaire),
 * puis chaque étape suivante enregistre les données techniques (saveTankData). La dernière étape utilise
 * l'enregistrement normal du formulaire (article existant) qui referme la fenêtre.
 * La modification d'un réservoir existant n'est pas concernée.
 */
(function ($) {
    'use strict';

    const STEPS = [
        { key: 'design',     label: 'Design' },
        { key: 'dimensions', label: 'Dimensions' },
        { key: 'welding',    label: 'Welding' },
        { key: 'fittings',   label: 'Fittings' },
        { key: 'insulation', label: 'Insulation' },
        { key: 'details',    label: 'Details & save' },
    ];

    const CSS = `
        .ispag-wizard-hidden{display:none !important}
        body.ispag-wizard-on .ispag-wizard-orig-save{display:none !important}
        .ispag-wizard-steps{display:flex;gap:6px;list-style:none;margin:0 0 18px;padding:0;flex-wrap:wrap}
        .ispag-wizard-steps li{flex:1;min-width:90px;text-align:center;padding:8px 6px;border-radius:6px;background:#f0f0f1;color:#666;font-size:12px;font-weight:600}
        .ispag-wizard-steps li.is-active{background:var(--ispag-red,#c00);color:#fff}
        .ispag-wizard-steps li.is-done{background:#e6f4ea;color:#1e7b34}
        .ispag-wizard-nav{display:flex;justify-content:space-between;gap:10px;margin:22px 0 6px;padding-top:14px;border-top:1px solid #eee}
        .ispag-wizard-footer-btns{display:inline-flex;gap:10px;margin-right:10px}
        #ispag-wizard-fittings-host #ispag-btn-save-tank-fittings{display:none !important}
        #ispag-wizard-fittings,#ispag-wizard-fittings-host{width:100%;max-width:none;box-sizing:border-box}
        #ispag-wizard-fittings-host .ispag-modal-fullscreen-inner{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;width:100%;height:auto;overflow:visible;box-sizing:border-box}
        #ispag-wizard-fittings-host .ispag-modal-fitting-right{order:1;flex:1 1 420px;width:auto;min-width:0;max-height:none;overflow:visible;box-sizing:border-box}
        #ispag-wizard-fittings-host .ispag-modal-fitting-left{order:2;flex:0 0 180px;width:180px;height:auto !important;min-height:0;max-height:none;padding:8px;text-align:center}
        #ispag-wizard-fittings-host .ispag-modal-fitting-left img,#ispag-wizard-fittings-host .ispag-modal-fitting-left svg{max-width:100%;max-height:240px;height:auto}
        .ispag-wizard-error{color:#b32d2e;margin:8px 0;font-weight:600}
        .ispag-wizard-panel{background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px 16px;margin-top:10px}
    `;

    let wizard = null; // un seul assistant actif à la fois

    function urlParam(name) {
        return new URLSearchParams(window.location.search).get(name);
    }

    function isNewTankForm($form) {
        return $form.length
            && !$form.attr('data-article-id')
            && !$form.data('article-id')
            && $form.find('#tank-dimensions-form').length
            && $form.find('#ispag-tank-form-container').length;
    }

    // Pied de la fenêtre (boutons Save / Cancel), situé hors du formulaire
    function findFooter($form) {
        let $f = $();
        $form.parents().each(function () {
            const $found = $(this).find('.ispag-modal-actions').first();
            if ($found.length) { $f = $found; return false; }
        });
        return $f.length ? $f : $('.ispag-modal-actions:visible').last();
    }

    function init($form) {
        if ($form.data('ispagWizard')) return;

        // Le pied de la fenêtre peut arriver après le formulaire : on réessaie quelques fois avant de se rabattre sur le bas du formulaire
        const $footer = findFooter($form);
        const tries = ($form.data('ispagWizardTries') || 0) + 1;
        $form.data('ispagWizardTries', tries);
        if (!$footer.length && tries < 15) return;

        $form.data('ispagWizard', true);

        if (!document.getElementById('ispag-wizard-css')) {
            $('<style id="ispag-wizard-css">').text(CSS).appendTo('head');
        }

        const dims  = $form.find('#tank-dimensions-form')[0];
        const grids = $(dims).children('.ispag-modal-grid').toArray();
        // 0: dimensions, 1: isolation + soudures, 2: commentaire
        if (grids.length < 3) return; // structure inattendue : on laisse le formulaire classique

        const designBlock = $form.children('.ispag-modal-grid').first()[0];
        const common      = $form.children('.ispag-bloc-common').toArray();
        const insField    = $(grids[1]).children('.ispag-field').eq(0)[0];
        const weldField   = $(grids[1]).children('.ispag-field').eq(1)[0];

        // Prix et workflow ne servent pas à la création d'un réservoir
        const hidden = $form.children('.ispag-modal-grid').filter(function () {
            return $(this).find('.workflow-checkboxes, .js-sales-price-input').length > 0;
        })[0];

        const panel = $('<div class="ispag-wizard-panel" id="ispag-wizard-fittings">' +
            '<h3 style="margin-top:0">Fittings</h3>' +
            '<p>The tank is saved. Add its fittings below; they are saved when you continue.</p>' +
            '<div id="ispag-wizard-fittings-host"></div>' +
            '<button type="button" id="open-tank-fittings-modal" style="display:none"></button>' +
            '</div>')[0];
        $(dims).before(panel);

        // [élément, étapes où il est visible] (index d'étape à partir de 0)
        const items = [
            [designBlock, [0]],
            [dims, [1, 2, 4, 5]],
            [$(dims).children('.card-header')[0], [1]],
            [grids[0], [1]],
            [grids[1], [2, 4]],
            [insField, [4]],
            [weldField, [2]],
            [grids[2], [5]],
            [panel, [3]],
            [hidden, []],
        ].concat(common.map(el => [el, [5]])).filter(i => i[0]);

        const $stepper = $('<ol class="ispag-wizard-steps"></ol>');
        STEPS.forEach((s, i) => $stepper.append($('<li>').text((i + 1) + '. ' + s.label).attr('data-step', i)));
        const $error = $('<div class="ispag-wizard-error" style="display:none"></div>');
        const $back  = $('<button type="button" class="ispag-btn ispag-btn-secondary-outlined">Back</button>');
        const $next  = $('<button type="button" class="ispag-btn ispag-btn-red-outlined">Next</button>');
        const $save  = $('<button type="submit" class="ispag-btn ispag-btn-red-outlined">Save</button>').attr('form', $form.attr('id') || 'ispag-edit-article-form');
        $form.prepend($stepper);
        $form.append($error);
        if ($footer.length) {
            // Boutons dans le pied de la fenêtre, à côté de Cancel ; le Save d'origine est masqué
            $footer.find('button[type="submit"]').addClass('ispag-wizard-orig-save');
            $footer.prepend($('<span class="ispag-wizard-footer-btns"></span>').append($back, $next, $save));
        } else {
            $form.append($('<div class="ispag-wizard-nav"></div>').append($back, $('<span></span>').append($next, $save)));
        }
        $('body').addClass('ispag-wizard-on');

        wizard = { $form, current: 0, articleId: 0, busy: false, awaiting: false, embedded: false, $inner: null, items, $stepper, $error, $back, $next, $save };

        $back.on('click', () => goto(wizard.current - 1));
        $next.on('click', onNext);
        show(0);
    }

    // L'éditeur de piquages (contenu de la modale plein écran) est déplacé dans l'étape 3, puis remis en place.
    function syncFittingsTrigger() {
        const w = wizard;
        const $t = w.$form.find('#open-tank-fittings-modal');
        const vals = {
            'article-id': w.articleId,
            'tank-diameter': w.$form.find('select[name="tank[diameter]"]').val() || '',
            'tank-pression': w.$form.find('input[name="tank[max_pressure]"]').val() || '',
            'tank-using-temp': w.$form.find('input[name="tank[temperature]"]').val() || '',
            'tank-insulation-thickness': w.$form.find('select[name="tank[InsulationThickness]"]').val() || '',
            'tank-supplier': w.$form.find('input[name="supplier"]').val() || '',
        };
        Object.keys(vals).forEach(k => { $t.attr('data-' + k, vals[k]); $t.data(k, vals[k]); });
        return $t;
    }

    function embedFittings() {
        const w = wizard;
        if (w.embedded || !w.articleId || typeof window.ispagOpenFittings !== 'function') return;
        const $inner = $('#tank-fittings-modal .ispag-modal-fullscreen-inner').first();
        const $host = $('#ispag-wizard-fittings-host');
        if (!$inner.length) {
            $host.text('The fittings editor is not available on this page. Add the fittings from the article list.');
            return;
        }
        w.$inner = $inner;
        $host.append($inner);
        w.embedded = true;
        window.ispagOpenFittings(syncFittingsTrigger(), true);
    }

    function unembedFittings() {
        const w = wizard;
        if (!w || !w.embedded || !w.$inner) return;
        $('#tank-fittings-modal').append(w.$inner);
        w.embedded = false;
    }

    function show(step) {
        const w = wizard;
        w.current = step;
        w.items.forEach(([el, steps]) => {
            const visible = steps.indexOf(step) !== -1;
            el.classList.toggle('ispag-wizard-hidden', !visible);
            if (visible && el.style.display === 'none') el.style.display = '';
        });
        w.$stepper.children().each(function (i) {
            $(this).toggleClass('is-active', i === step).toggleClass('is-done', i < step);
        });
        w.$back.toggle(step > 0);
        w.$next.toggle(step < STEPS.length - 1);
        w.$save.toggle(step === STEPS.length - 1);
        // Une fois le réservoir créé, on ne revient plus au design (le type est fixé)
        if (w.articleId && step === 0) w.$back.hide();
        if (w.articleId && step === 1) w.$back.hide();
        setError('');
        if (step === 3) embedFittings(); else unembedFittings();
        // Les listes dépendantes (diamètres…) se calculent à la volée : on relance leur logique
        $(document).trigger('ispag:wizard_step', [STEPS[step].key]);
    }

    function goto(step) {
        if (step < 0 || step >= STEPS.length || wizard.busy) return;
        show(step);
    }

    function setError(msg) {
        wizard.$error.text(msg || '').toggle(!!msg);
    }

    function setBusy(busy) {
        wizard.busy = busy;
        wizard.$next.prop('disabled', busy).text(busy ? 'Saving…' : 'Next');
        wizard.$back.prop('disabled', busy);
    }

    function validate(step) {
        const $f = wizard.$form;
        if (step === 0) {
            if (!$f.find('select[name="tank[materiau]"]').val()) return 'Choose a material.';
        }
        if (step === 1) {
            if (!$f.find('select[name="tank[diameter]"]').val()) return 'Choose a diameter.';
            if (!(parseFloat($f.find('input[name="tank[volume]"]').val()) > 0)) return 'Enter the volume.';
        }
        return '';
    }

    function onNext() {
        const w = wizard;
        if (w.busy) return;
        const err = validate(w.current);
        if (err) return setError(err);
        setError('');

        // Fin de l'étape « Dimensions » : création du réservoir
        if (w.current === 1 && !w.articleId) return createTank();
        // Réservoir déjà créé : enregistrement des données techniques de l'étape
        if (w.current >= 1 && w.articleId && typeof saveTankData === 'function') {
            if (w.current === 3 && w.embedded && typeof saveFittings === 'function') {
                setBusy(true);
                return saveFittings(true).then(() => { setBusy(false); saveStep(); });
            }
            return saveStep();
        }
        goto(w.current + 1);
    }

    function createTank() {
        wizard.awaiting = true;
        setBusy(true);
        // Circuit d'enregistrement habituel du formulaire (article + données techniques)
        wizard.$form.trigger('submit');
    }

    function saveStep() {
        setBusy(true);
        saveTankData(wizard.articleId, urlParam('poid') ? 'true' : 'false')
            .done(() => { setBusy(false); goto(wizard.current + 1); })
            .fail(err => { setBusy(false); setError('Save failed' + (err && err.message ? ' : ' + err.message : '')); });
    }

    function onTankCreated(articleId) {
        const w = wizard;
        w.articleId = articleId;
        w.awaiting = false;
        // Le formulaire devient celui d'un article existant : l'enregistrement final met à jour et referme la fenêtre
        w.$form.attr('data-article-id', articleId).data('article-id', articleId);
        $('#current-editing-article-id').val(articleId);
        w.$form.find('input[name="tank[article_id]"]').val(articleId);

        syncFittingsTrigger();
        setBusy(false);
        goto(2);
    }

    function actionOf(settings) {
        const d = typeof settings.data === 'string' ? settings.data : '';
        const m = d.match(/(?:^|&)action=([^&]+)/);
        return m ? m[1] : '';
    }

    // Suivi des enregistrements lancés par le circuit habituel (details.js)
    let pendingId = 0;
    $(document).ajaxSuccess(function (e, xhr, settings) {
        if (!wizard || !wizard.awaiting) return;
        const action = actionOf(settings);
        const res = xhr.responseJSON;
        if (action === 'ispag_save_article' && res && res.success && res.data && res.data.article_id) {
            pendingId = res.data.article_id;
        } else if (action === 'ispag_save_tank_data') {
            if (res && res.success) {
                onTankCreated(pendingId || parseInt($('#current-editing-article-id').val(), 10) || 0);
            } else {
                wizard.awaiting = false;
                setBusy(false);
                setError('The tank could not be saved. Check the values and try again.');
            }
        } else if (action === 'ispag_save_article' && !(res && res.success)) {
            wizard.awaiting = false;
            setBusy(false);
        }
    });
    $(document).ajaxError(function (e, xhr, settings) {
        if (!wizard || !wizard.awaiting) return;
        const action = actionOf(settings);
        if (action === 'ispag_save_article' || action === 'ispag_save_tank_data') {
            wizard.awaiting = false;
            setBusy(false);
            setError('Server error while saving. Try again.');
        }
    });

    function scan() {
        const $form = $('#ispag-edit-article-form');
        if (isNewTankForm($form)) {
            init($form);
        } else if (wizard && !document.body.contains(wizard.$form[0])) {
            unembedFittings(); // rend l'éditeur de piquages à sa modale avant de l'oublier
            wizard = null; // fenêtre refermée
            pendingId = 0;
            $('.ispag-wizard-footer-btns').remove();
            $('.ispag-wizard-orig-save').removeClass('ispag-wizard-orig-save');
            $('body').removeClass('ispag-wizard-on');
        }
    }

    let scheduled = false;
    new MutationObserver(function () {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(function () { scheduled = false; scan(); });
    }).observe(document.documentElement, { childList: true, subtree: true });

    $(scan);
})(jQuery);
