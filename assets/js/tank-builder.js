let isCalculatingHeight = false; // Notre indicateur
let isCalculatingVolume = false; // Notre indicateur

jQuery(document).ready(function($) {

     const $typeSelect = $('#tank-typ'); // ou adapte l’ID si différent
    const selectedOption = $typeSelect.find('option:selected');
    const typId = selectedOption.data('id');

    if (typId) {
        updateTankDefaultsFromSelect($typeSelect);
    }
    $('#tank-typ').on('change', function () {
        // console.log('in OnChange function');
        const typId = $(this).find(':selected').data('id');
        if (typId) {
            updateTankDefaults(typId);
        }
    });
    $('select[name="tank[materiau]"]').on('change', function() {
        // Appelle la fonction qui gère les fournisseurs et les diamètres
        updateSupplierByMaterial(this);
    });
   
});

// Utilise 'change' ou 'input' plutôt que 'blur' pour une réactivité immédiate
$(document).on('change', 'input[name="tank[nbWelding]"]', async function() {
    // 1. Correction de la faute de frappe : nbWelding (et pas ndWelding)
    // 2. Conversion en entier pour la comparaison
    const nbWelding = parseInt($(this).val(), 10); 
    const computedSections = 4;

    // Si on a plus de 2 soudures (donc 3 tronçons ou plus)
    if (nbWelding > computedSections) {

        let weldingMsg = ispag_texts.warning_nb_welding 
            ? ispag_texts.warning_nb_welding.replace('%d', computedSections).replace('%d', computedSections)
            : `Attention, la plateforme ne peut pas ajouter automatiquement les soudure pour plue de ${computedSections} soudures`;

        const confirmed = await ispagConfirm(weldingMsg, {
            labelOk:     ispag_texts.continue,
            labelCancel: ispag_texts.cancel || 'Annuler',
            danger:      false,
        });

        // Si l'utilisateur annule, on remet la valeur à 2 ou on vide
        if (!confirmed) {
            $(this).val(2); 
            return;
        }
    }
});

// Listener sur le changement d'isolation
$(document).on('change', 'select[name="tank[insulation]"]', function() {
    const insulationId = $(this).val();
    const typeId = $('#tank-typ').find(':selected').data('id'); 
    
    console.log("Changement isolation détecté :", insulationId, "pour Type :", typeId);
    updateInsulationDependencies(insulationId, typeId);
});

async function updateInsulationDependencies(insulationId, typeId) {
    await setIspagTankRestrictionsValue();
    ispagApplyTankRules(typeId);
}

 
async function updateTankDefaultsFromSelect(selectEl) {
    // console.log('updateTankDefaultsFromSelect  call --> setIspagTankRestrictionsValue');
    await setIspagTankRestrictionsValue(); // ⏳ attend que restrictions soit chargé

    const typId = String(jQuery(selectEl).find(':selected').data('id'));
    if (typId) {
        $('#tank-dimensions-form').show();
        updateTankDefaults(typId);
    } else {
        $('#tank-dimensions-form').hide();
    }
}
function updateTankDefaults(typId) {
    if (!restrictions.typ || !restrictions.typ[typId]) return;

    const defaults = restrictions.typ[typId].default || {};
    const allowed = restrictions.typ[typId].restrictions || {};

    // --- Matériau ---
    const $materialSelect = $('select[name="tank[materiau]"]');
    const currentMaterial = String($materialSelect.val());
    if (defaults.Material !== undefined && (!currentMaterial || (allowed.Material && !allowed.Material.includes(parseInt(currentMaterial))))) {
        $materialSelect.val(defaults.Material).trigger('change'); // 👈 Déclenche updateSupplierByMaterial
    }

    // --- Support ---
    const $supportSelect = $('select[name="tank[support]"]');
    const currentSupport = String($supportSelect.val());
    if (defaults.Support !== undefined && (!currentSupport || (allowed.Support && !allowed.Support.includes(parseInt(currentSupport))))) {
        $supportSelect.val(defaults.Support).trigger('change');
    }

    // --- Pression service ---
    if (defaults.MaxPressure !== undefined) {
        const $field = $('input[name="tank[max_pressure]"]');
        if (!$field.val() || $field.val() == 0) {
            $field.val(defaults.MaxPressure);
        }
    }

    // --- Pression d’essai ---
    if (defaults.TestPressure !== undefined) {
        const $field = $('input[name="tank[test_pressure]"]');
        if (!$field.val() || $field.val() == 0) {
            $field.val(defaults.TestPressure);
        }
    }

    // --- Isolation ---
    if (defaults.insulation !== undefined) {
        $('input[name="tank[insulation]"]').val(defaults.insulation).trigger('change');
    }

    // --- Appliquer les restrictions ---
    restrictTankOptions(typId);
}


function restrictTankOptions(typId) {
    ispagApplyTankRules(typId);
}

// ---------------------------------------------------------------------------
// Règles de conception (base / JSON) appliquées au fur et à mesure de l'avancement :
//   type        -> matériaux, supports, types d'isolation et épaisseurs autorisés
//   isolation   -> épaisseurs et revêtements autorisés (croisés avec ceux du type)
// Les valeurs non autorisées sont retirées des listes (pas seulement masquées).
// ---------------------------------------------------------------------------
function ispagCurrentTypeId() {
    const $t = jQuery('#tank-typ, select[name="tank[type]"], input[name="tank[type]"]').first();
    let id = $t.find(':selected').data('id') || $t.val();
    if (!id) id = jQuery('#ispag-product-modal-content h2').data('id');
    return id ? String(id) : '';
}

function ispagFilterSelect($sel, allowed, keep) {
    if (!$sel.length) return;
    if (!$sel.data('ispagAllOptions')) $sel.data('ispagAllOptions', $sel.children('option').clone().prop('disabled', false).css('display', ''));

    const current = String($sel.val() ?? '');
    const allowedStr = Array.isArray(allowed) ? allowed.map(String) : null;
    $sel.empty();
    $sel.data('ispagAllOptions').each(function () {
        const val = String(this.value);
        if (allowedStr === null || keep.includes(val) || allowedStr.includes(val)) {
            $sel.append(jQuery(this).clone());
        }
    });

    const has = $sel.children('option').toArray().some(o => String(o.value) === current);
    if (has) {
        $sel.val(current);
    } else {
        $sel.prop('selectedIndex', 0); // valeur retirée : retour au premier choix (« -- Choose -- » / « None »)
        $sel.trigger('change');
    }
}

function ispagIntersect(lists) {
    const filled = lists.filter(l => Array.isArray(l));
    if (!filled.length) return null; // aucune règle : tout est proposé
    return filled.reduce((a, b) => a.map(String).filter(v => b.map(String).includes(v)));
}

function ispagApplyTankRules(typId) {
    const $ = jQuery;
    if (typeof restrictions === 'undefined' || !restrictions) return;
    typId = String(typId || ispagCurrentTypeId());
    const typ = restrictions.typ && restrictions.typ[typId];
    const rt = (typ && typ.restrictions) || {};

    ispagFilterSelect($('select[name="tank[materiau]"]'), rt.Material || null, ['']);
    ispagFilterSelect($('select[name="tank[support]"]'), rt.Support || null, ['']);
    ispagFilterSelect($('select[name="tank[insulation]"]'), rt.insulation || null, ['', '0']);

    const insId = String($('select[name="tank[insulation]"]').val() || '');
    const ins = insId && insId !== '0' && restrictions.insulation ? restrictions.insulation[insId] : null;

    // Catalogue : épaisseurs et revêtements pour lesquels un article existe pour le type d'isolation choisi
    const hasInsulation = insId && insId !== '0';
    const cat = hasInsulation && insulationCatalog ? (insulationCatalog[insId] || { thickness: [], cover: [] }) : null;

    ispagFilterSelect($('select[name="tank[InsulationThickness]"]'), ispagIntersect([rt.InsulationThickness, ins && ins.InsulationThickness, cat && cat.thickness]), ['', '0']);
    ispagFilterSelect($('select[name="tank[insulationCover]"]'), ispagIntersect([ins && ins.insulationCover, cat && cat.cover]), ['', '0', '53']);
}

// L'assistant de création : les règles sont réappliquées à chaque étape
jQuery(document).on('ispag:wizard_step', async function () {
    await setIspagTankRestrictionsValue();
    ispagApplyTankRules();
});

function updateSupplierByMaterial(selectEl) {
    const $ = jQuery;
    const materialId = $(selectEl).val();
    const typeId = $('#tank-typ').find(':selected').data('id');

    const $supplierDatalist = $('#supplier-list');
    const $supplierInput = $('input[name="supplier"]');

    // 1. Fournisseurs du matériau et du type (le premier de chaque liste est le fournisseur par défaut)
    const matSuppliers = restrictions.material?.[materialId]?.default?.supplier_name || [];
    const typSuppliers = restrictions.typ?.[typeId]?.default?.supplier_name || [];
    const allSuppliers = new Set([...matSuppliers, ...typSuppliers]);

    // Même règle que côté serveur : un fournisseur commun au matériau et au type, sinon celui du matériau, sinon celui du type
    const common = matSuppliers.find(s => typSuppliers.includes(s));
    const preferred = common || matSuppliers[0] || typSuppliers[0];

    // 2. Mettre à jour la datalist
    $supplierDatalist.empty();
    const supplierArray = Array.from(allSuppliers);
    supplierArray.forEach(s => $supplierDatalist.append($('<option>').val(s)));

    // 3. Gérer le fournisseur actuel
    const currentSupplier = $supplierInput.val();
    if (supplierArray.length > 0) {
        if (!allSuppliers.has(currentSupplier)) {
            $supplierInput.val(preferred);
            console.log(`[SUPPLIER] "${currentSupplier}" non autorisé. Remplacé par : ${preferred}`);
        }
        // Sinon, on garde currentSupplier
    }

    // 4. Mettre à jour les diamètres
    if (materialId) {
        updateDiameterDatalistByMaterial(materialId);
    }
}

async function updateDiameterDatalistByMaterial(materialId) {
    // 👇 Vérifier que les données sont chargées
    if (!isDataLoaded) {
        await setIspagTankRestrictionsValue();
        await new Promise((resolve) => {
            jQuery(document).one('ispag:restrictions_loaded', resolve);
        });
    }

    const $select = jQuery('select[name="tank[diameter]"]');
    if (!$select.length) {
        console.error('[ERROR] Élément select[name="tank[diameter]"] introuvable.');
        return;
    }

    // 👇 Vérifier que arrayBottomHeight[materialId] existe
    if (!arrayBottomHeight || !arrayBottomHeight[materialId]) {
        console.error(`[ERROR] arrayBottomHeight[${materialId}] introuvable.`);
        $select.empty();
        $select.append(new Option('-- Select a material --', ''));
        return;
    }

    const dataForMaterial = arrayBottomHeight[materialId];
    const diameters = Object.keys(dataForMaterial)
        .filter(d => parseFloat(dataForMaterial[d]) > 0)
        .map(Number);

    const currentDiam = $select.attr('data-current-diameter');
    $select.empty();
    $select.append(new Option('-- Select --', ''));

    diameters.forEach(d => {
        const option = new Option(d + ' mm', d);
        if (currentDiam && d.toString() === currentDiam.toString()) {
            option.selected = true;
        }
        $select.append(option);
    });

    const finalVal = $select.val();
    if (finalVal) {
        $select.attr('data-current-diameter', finalVal);
    }

    $select.trigger('change');
    if ($select.data('select2')) {
        $select.trigger('change.select2');
    }
}

/**
 * Met à jour la liste des diamètres en fonction du type de réservoir.
 */
async function updateDiameterDatalistByType(typeId, forcedDiameter = null) {
    const materialId = $('select[name="tank[materiau]"]').val();
    if (materialId) {
        await updateDiameterDatalistByMaterial(materialId);
    }
}
 


//Calcul de la hauteur de la cuve
$(document).on('change', 
    'select[name="tank[diameter]"], input[name="tank[diameter]"], input[name="tank[volume]"], input[name="tank[bottom_height]"], input[name="tank[clearance]"]', 
    function() {
    
        // --- VÉRIFICATION CORRIGÉE : on cherche l'élément à chaque événement ---
        if (!$('#tank-auto-calculate').is(':checked')) {
            // Si la case n'est PAS cochée ou n'existe pas encore (peu probable ici), on arrête le script.
            return;
        }
        const diameter = parseFloat($('select[name="tank[diameter]"]').val());
        const volume = parseFloat($('input[name="tank[volume]"]').val());
        const bottomHeight = parseFloat($('input[name="tank[bottom_height]"]').val()) || 0;
        const clearance = parseFloat($('input[name="tank[clearance]"]').val()) || 0;

        if (diameter && volume) {
            isCalculatingHeight = true;
            isCalculatingVolume = false;

            const height = calculateTankHeight();
            $('input[name="tank[height]"]').val(height);

            //cote de basculement
            // const tipping = calculateTipping(diameter, volume, bottomHeight, clearance);
            const tipping = calculateTipping();
            $('input[name="tank[tipping]"]').val(tipping);
            setFlagToFalse();
        }
    }
);

// Fonction pour gérer l'état (activé/désactivé) du champ pression d'essais
function toggleTestPressureState() {
    const isAuto = $('#tank-auto-calculate').is(':checked');
    const inputPressionEssais = $('input[name="tank[test_pressure]"]');
    
    if (isAuto) {
        inputPressionEssais.prop('readonly', true).css('background-color', '#f0f0f0');
    } else {
        inputPressionEssais.prop('readonly', false).css('background-color', '#fff');
    }
}

// 1. Au chargement et quand on clique sur la case à cocher
$(document).on('change', '#tank-auto-calculate', function() {
    toggleTestPressureState();
    
    // Si on vient de cocher, on force le premier calcul
    if ($(this).is(':checked')) {
        $('input[name="tank[max_pressure]"]').trigger('change');
    }
});

// 2. Logique de calcul de la pression
$(document).on('change', '#tank-material, input[name="tank[max_pressure]"]', function() {
    
    if (!$('#tank-auto-calculate').is(':checked')) return;

    const materiau = $('#tank-material').val();
    const pressionService = parseFloat($('input[name="tank[max_pressure]"]').val());
    const inputPressionEssais = $('input[name="tank[test_pressure]"]');

    if (isNaN(pressionService) || pressionService <= 0) return;

    let multiplicateur = 0;

    if (pressionService >= 16) {
        multiplicateur = 1.44;
    } else {
        if (materiau == "2") { // Acier
            multiplicateur = 1.5;
        } else if (materiau == "1" || materiau == "3") { // Inox
            multiplicateur = 2;
        }
    }

    if (multiplicateur > 0) {
        const pressionEssais = (pressionService * multiplicateur).toFixed(1);
        inputPressionEssais.val(pressionEssais);
    }
});

// Initialisation au chargement de la page (si la case est déjà cochée par défaut)
toggleTestPressureState();

$(document).on('change', 
    
    'input[name="tank[height]"]', 
    function () {

        // --- VÉRIFICATION CORRIGÉE : on cherche l'élément à chaque événement ---
        if (!$('#tank-auto-calculate').is(':checked')) {
            // Si la case n'est PAS cochée ou n'existe pas encore (peu probable ici), on arrête le script.
            return;
        }
        const diameter = parseFloat($('select[name="tank[diameter]"]').val());
        const height = parseFloat($('input[name="tank[height]"]').val());
        const bottomHeight = parseFloat($('input[name="tank[bottom_height]"]').val()) || 0;
        const clearance = parseFloat($('input[name="tank[clearance]"]').val()) || 0;

        if (diameter && height) {
            isCalculatingHeight = false;
            isCalculatingVolume = true;

            const volume = calculateTankVolume(diameter, height, bottomHeight, clearance);
            $('input[name="tank[volume]"]').val(volume);

            //cote de basculement
            // const tipping = calculateTipping(diameter, volume, bottomHeight, clearance);
            const tipping = calculateTipping();
            $('input[name="tank[tipping]"]').val(tipping);
            setFlagToFalse();
        }
    }
);


//Fonction qui calcul le volume d'un fond bombé en fonction de la hauteur et du diamètre (ellipsoide)
//OK Fonctionnel et juste
function calculateBottomVolume() {

    var diameter = parseFloat($('select[name="tank[diameter]"]').val());
    var h = chooseBottomHeight();

    var radius = diameter / 2;

    if (h <= 0) return 0;

    // volume en mm³
    // const volumeBottom = ((4/3) * (Math.PI * Math.pow(radius, 2)) * h) ;
    var volumeBottom = ((2/3) * (Math.PI * Math.pow(radius, 2)) * h) ;

    // convertir en litres (1 litre = 1 000 000 mm³)
    return volumeBottom / 1000000;
}

function chooseBottomHeight(){
    var material = $('select[name="tank[materiau]"]').val();;
    var diameter = parseFloat($('select[name="tank[diameter]"]').val());

    return arrayBottomHeight[material][diameter];

}
function calculateTankHeight() {
    if ( isCalculatingVolume) return 0;
     
    var bottomHeight = chooseBottomHeight();
    var diameter = parseFloat($('select[name="tank[diameter]"]').val()) || 0;
    var volume = parseFloat($('input[name="tank[volume]"]').val()) || 0;
    var clearance = parseFloat($('input[name="tank[clearance]"]').val()) || 0;

    // console.log('********************** START calculateTankHeight ****************************************************');
    // console.log('diameter : ', diameter);
    // console.log('volume : ', volume);
    // console.log('bottomHeight : ', bottomHeight);
    // console.log('clearance : ', clearance);
    
    // clearance = clearance || 0;

    //Calcul du volume d'un fond bombé --> OK
    const bottomVolume = calculateBottomVolume();
    // console.log('bottomVolume : ', bottomVolume);

    const volumeCylinder = volume - 2*bottomVolume;
    // console.log('volumeCylinder : ', volumeCylinder);
    

    if (volumeCylinder <= 0) return bottomHeight + clearance; // cas volume trop petit

    const radius = diameter / 2;
    // hauteur cylindre mm
    const heightCylinder = (volumeCylinder * 1000000) / (Math.PI * Math.pow(radius, 2));
    // console.log('heightCylinder : ', heightCylinder);
    const totalHeight = Math.round(heightCylinder + 2*bottomHeight + clearance);
    const Height = Math.round(totalHeight / 10) * 10;
    
    // console.log('Hauteur totale (avant arrondi) : ', totalHeight);
    // console.log('Hauteur finale (arrondie au 10) : ', Height);
    // console.log('********************** END calculateTankHeight ****************************************************');
    
    return Height;
}
function calculateTankVolume(diameter, height, bottomHeight, clearance) {
    if ((!diameter || !height) && isCalculatingHeight) return 0;
    
    isCalculatingVolume = true;
    bottomHeight = chooseBottomHeight();
    // console.log('********************** START calculateTankVolume ****************************************************');
    // console.log('diameter : ', diameter);
    // console.log('height : ', height);
    // console.log('bottomHeight : ', bottomHeight);
    // console.log('clearance : ', clearance);
    const radius = diameter / 2;
    const hCyl = height - clearance - 2 * bottomHeight;
    // console.log('Body height : ', hCyl);

    if (hCyl <= 0) return 0;

    const volumeCylinder = Math.PI * Math.pow(radius, 2) * hCyl / 1000000; // en litres
    // console.log('volumeCylinder : ', volumeCylinder);
    const volumeBottom = calculateBottomVolume();
    // console.log('bottom volume : ', volumeBottom);

    const TotalTankVolume = Math.round(volumeCylinder + volumeBottom + volumeBottom);
    const tankVolume = Math.round(TotalTankVolume / 10) * 10;

    // console.log('Volume totale (avant arrondi) : ', TotalTankVolume);
    // console.log('Volume finale (arrondie au 10) : ', tankVolume);
    // console.log('********************** END calculateTankVolume ****************************************************');
    
    return tankVolume;
}

function setFlagToFalse(){
    isCalculatingHeight = false;
    isCalculatingVolume = false;
}

// function calculateTipping(diameter, volume, bottomHeight, clearance){
//     const radius = diameter / 2;
//     const Height = calculateTankHeight(diameter, volume, bottomHeight, clearance);

//     return Math.round(Math.sqrt(Math.pow(radius, 2) + Math.pow(Height, 2)));
// }
function calculateTipping(){
    var diameter = parseFloat($('select[name="tank[diameter]"]').val()) || 0;
    var Height = parseFloat($('input[name="tank[height]"]').val()) || 0;
    const radius = diameter / 2;

    return Math.round(Math.sqrt(Math.pow(radius, 2) + Math.pow(Height, 2)));
}

function findClosestDiameter(targetVolume, bottomHeight, clearance, conceptionId) {
    let bestDiameter = null;
    let smallestDiff = Infinity;

    const diameters = Object.keys(arrayBottomHeight[conceptionId] || {}).map(Number);

    diameters.forEach(d => {
        const v = calculateTankVolume(d, null, bottomHeight, clearance);
        const diff = Math.abs(v - targetVolume);
        if (diff < smallestDiff) {
            bestDiameter = d;
            smallestDiff = diff;
        }
    });

    return bestDiameter;
}



// Sauvegarde des données techniques du réservoir
function saveTankData(articleId, is_purchase = false) {

    saveHeatExchangerData(articleId, is_purchase);

    const tank = {
        type:                   $('[name="tank[type]"]').val(),
        materiau:               $('[name="tank[materiau]"]').val(),
        support:                $('[name="tank[support]"]').val(),
        diameter:               $('[name="tank[diameter]"]').val(), // ou $('#tank-diameter').val()
        height:                 $('[name="tank[height]"]').val(),
        volume:                 $('[name="tank[volume]"]').val(),
        tipping:                $('[name="tank[tipping]"]').val(),
        max_pressure:           $('[name="tank[max_pressure]"]').val(),
        test_pressure:          $('[name="tank[test_pressure]"]').val(),
        clearance:              $('[name="tank[clearance]"]').val(),
        temperature:            $('[name="tank[temperature]"]').val(),
        insulation:             $('[name="tank[insulation]"]').val(),
        insulationCover:        $('[name="tank[insulationCover]"]').val(),
        InsulationThickness:    $('[name="tank[InsulationThickness]"]').val(),
        nbWelding:              $('[name="tank[nbWelding]"]').val(),
        weldingByClient:        $('[name="tank[weldingByClient]"]').is(':checked') ? 1 : 0,
        openComment:            $('[name="tank[openComment]"]').val(),
    };
    
    const form = $('.ispag-edit-article-form');
    const deal_id = getUrlParam('deal_id');
    const achat_id = getUrlParam('poid');



    console.log("Données envoyées au serveur :", tank); // Pour tes tests

    const payload = {
        action: 'ispag_save_tank_data',
        _ajax_nonce: ISPAG_TANK.nonce,
        deal_id: deal_id,
        achat_id: achat_id,
        article_id: articleId,
        is_purchase: is_purchase,
        tank: tank
    };
    // Largeur de porte (cm) : enregistrée avec la soudure pour figurer dans sa description (seulement si le champ est affiché)
    const $doorWidth = $('[name="door_width"]');
    if ($doorWidth.length) payload.door_width = $doorWidth.val();

    return $.post(ISPAG_TANK.ajax_url, payload).then(response => {
        // Une réponse sans succès (ou non JSON) doit être traitée comme un échec
        if (!response || !response.success) {
            console.error('Error cuve : ', response && response.data);
            const d = response && response.data;
            const msg = (d && (d.message || d.sql_error)) || 'Invalid server response';
            ispagResetSaveButtons();
            return $.Deferred().reject({ message: msg, response: response });
        }
        console.log('Succès sauvegarde technique', response.data);
        return response;
    }, xhr => {
        console.error('Error critique AJAX', xhr.responseText);
        ispagResetSaveButtons();
        return $.Deferred().reject({ message: 'Invalid server response', xhr: xhr });
    });
}

// Mémorise l'état des boutons d'enregistrement au clic (avant que le code appelant ne les désactive)
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.ispag-edit-article-form button, .ispag-edit-article-form input[type="submit"]');
    if (!btn || btn.disabled) return;
    btn.dataset.ispagOrigHtml = btn.tagName === 'INPUT' ? btn.value : btn.innerHTML;
}, true);

// Réactive les boutons du formulaire d'article pour pouvoir refaire un essai sans recharger la page
function ispagResetSaveButtons() {
    document.querySelectorAll('.ispag-edit-article-form button, .ispag-edit-article-form input[type="submit"]').forEach(btn => {
        btn.disabled = false;
        btn.classList.remove('loading', 'is-loading', 'disabled');
        if (btn.dataset.ispagOrigHtml !== undefined) {
            if (btn.tagName === 'INPUT') btn.value = btn.dataset.ispagOrigHtml;
            else btn.innerHTML = btn.dataset.ispagOrigHtml;
        }
    });
    document.dispatchEvent(new CustomEvent('ispag_tank_save_failed'));
}


jQuery(document).ready(function($) {
    // Délégation sur un parent permanent
    // Charge l'éditeur de piquages pour l'article porté par $el (data-*). embed=true : pas d'ouverture de la modale
    // (utilisé par l'assistant de création, qui déplace l'éditeur dans son étape « Fittings »).
    window.ispagOpenFittings = function($el, embed) {
        const articleId = $el.data('article-id');
        const purchaseArticleId = $el.data('purchase-article-id');
        const tank_diam = $el.data('tank-diameter');
        const tank_pression = $el.data('tank-pression');
        const tank_using_temp = $el.data('tank-using-temp');
        const tank_insulation_thickness = $el.data('tank-insulation-thickness');
        const supplier_name = $el.data('tank-supplier');

        

        if (purchaseArticleId) {
            // Si l'ID Purchase existe, on est en mode achat
            finalIdToEdit = purchaseArticleId;
            mode = "purchase";
        } else {
            // Sinon, on prend l'ID projet et on passe en mode projet
            finalIdToEdit = articleId;
            mode = "project";
        }

        // Met à jour le lien 3D
        $('#tank-fittings-modal a.display-tank-3d').attr('href', '/rendu-3d/?article_id=' + articleId);

        $('#current-editing-article-id').val(finalIdToEdit);
        $('#current-tank-diam').val(tank_diam);
        $('#current-tank-pression').val(tank_pression);
        $('#current-tank-using-temp').val(tank_using_temp);
        $('#current-insulation-thickness').val(tank_insulation_thickness);
        $('#tank-supplier-display').val(supplier_name).attr('data-value', supplier_name);

        $('input[name="isProjectOrPurchase"]').val(mode);

        console.log(`%c MODE DÉTECTÉ : ${mode.toUpperCase()} (ID: ${finalIdToEdit})`, "background: #34495e; color: #fff; padding: 2px 5px;");

        $('#fittings-form').html('<p>Loading...</p>');
        if (!embed) { $('#tank-fittings-modal').fadeIn(); }

        return $.post(ajaxurl, {
            action: 'ispag_load_fittings_form',
            article_id: articleId
        }, function(response) {
            // console.log(response);
            if (response.success) {
                $('#fittings-form').html(response.data['html']);
                $('#ispag-modal-svg').html(response.data['svg']);

                // 🔥 Déclenchement de l'événement pour initialiser le snapshot du change-tracker
                document.dispatchEvent(new CustomEvent('modal_fitting_loaded', { 
                    detail: { mode: mode, articleId: finalIdToEdit } 
                }));

                // 🔥 EXECUTION DU CALCUL INITIAL DU PRIX
                setTimeout(function() {
                    updateFittingsPrice();
                }, 50);

            } else {
                $('#fittings-form').html('<p>Loading error</p>');
            }
        });
    };

    $(document).on('click', '#open-tank-fittings-modal', function() {
        window.ispagOpenFittings($(this), false);
    });

    $(document).on('click', '.ispag-modal-close', function() {
        closeFittingsModal()
    });
    
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#tank-fittings-modal').is(':visible')) {
            closeFittingsModal();
        }
    });
});

document.addEventListener('click', function(e) {
    const form = document.getElementById('fittings-form');
    if (!form) return;

    // Récupération des conteneurs spécifiques
    const fittingsContainer = document.getElementById('fittings-container');
    const weldingContainer = document.getElementById('welding-container');

    // 1. GESTION DES AJOUTS
    const addFittingBtn = e.target.closest('#add-fitting-row');
    const addWeldingBtn = e.target.closest('#add-welding-row');

    if (addFittingBtn) {
        const template = document.getElementById('fitting-row-template');
        if (template && fittingsContainer) {
            fittingsContainer.appendChild(template.content.cloneNode(true));
            if (typeof updateFittingsPrice === "function") updateFittingsPrice();
        }
        return;
    }
    
    if (addWeldingBtn) {
        const template = document.getElementById('welding-row-template');
        if (template && weldingContainer) {
            weldingContainer.appendChild(template.content.cloneNode(true));
            // Pas d'update prix ici car le welding n'impacte pas le calcul fittings
        }
        return;
    }

    // 2. GESTION DE LA SUPPRESSION
    const removeBtn = e.target.closest('.btn-remove, .btn-delete-fitting');
    if (removeBtn) {
        const row = removeBtn.closest('.fitting-row, .welding-row');
        if (row) {
            row.remove();
            if (typeof updateFittingsPrice === "function") updateFittingsPrice();
        }
        return;
    }

    // 3. GESTION DE LA DUPLICATION
    const duplicateBtn = e.target.closest('.btn-duplicate');
    if (duplicateBtn) {
        const row = duplicateBtn.closest('.fitting-row');
        if (!row) return;

        const clone = row.cloneNode(true);

        // Synchronisation des valeurs
        row.querySelectorAll('select').forEach((select, i) => {
            clone.querySelectorAll('select')[i].value = select.value;
        });
        row.querySelectorAll('input:not([type="hidden"])').forEach((input, i) => {
            clone.querySelectorAll('input:not([type="hidden"])')[i].value = input.value;
        });

        // Reset IDs
        const hiddenInput = clone.querySelector('input[name="fitting[id][]"]');
        if (hiddenInput) hiddenInput.value = '0';
        clone.dataset.id = '0';
        clone.querySelectorAll('.btn-duplicate, .btn-delete-fitting').forEach(btn => {
            btn.dataset.fittingId = '0';
        });

        // Ajout dans le BON conteneur (celui d'origine de la ligne)
        row.parentNode.appendChild(clone);
        
        if (typeof updateFittingsPrice === "function") updateFittingsPrice();
    }
});

function closeFittingsModal() {
    $('#tank-fittings-modal').fadeOut(400, function() {
        console.log('[FITTINGS MODAL] Modale fermée. Déclenchement de l\'événement modal_closed...');
        
        // 🔥 Déclenchement de l'événement personnalisé à la fermeture
        document.dispatchEvent(new CustomEvent('modal_fitting_closed', {
            detail: { formId: 'fittings-form' }
        }));
    });
}

const saveBtn = document.getElementById('ispag-btn-save-tank-fittings');
if (saveBtn) {
    saveBtn.addEventListener('click', function () {
        // On passe l'élément bouton à saveFittings
        saveFittings(false, this); 
    });
}


const selectors = [
    '[name="fitting[diameter][]"]',
    '[name="fitting[type][]"]',
    '[name="fitting[accessories][]"]',
    '[name="fitting[madeFor][]"]',
    '[name="fitting[height][]"]',
    '[name="fitting[angle][]"]'
];

const form = document.getElementById('fittings-form');

if (form) {
    form.addEventListener('change', function (e) {
        if (selectors.some(sel => e.target.matches(sel))) {
            saveFittings(true); // autosave sans fermer
        }
    });
}

function saveFittings(autoSave = false, btnElement = null) {
    const form = document.getElementById('fittings-form');
    if (!form) return Promise.resolve();

    const formData = new FormData(form);
    const articleId = document.querySelector('input[name="article_id"]').value;

    formData.append('action', 'ispag_save_fittings');
    formData.append('article_id', articleId); 

    // --- ÉTAT CHARGEMENT ---
    let originalHtml = "";
    if (btnElement && !autoSave) {
        btnElement.disabled = true; // Désactive pour éviter le double clic
        originalHtml = btnElement.innerHTML;
        // On remplace le contenu par un spinner (FontAwesome)
        btnElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    }

    return fetch(ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(response => {
        if (response.success) {
            // Mise à jour du SVG
            if (response.data?.drawing && $("#ispag-modal-svg").length) {
                $("#ispag-modal-svg").html(response.data.drawing);
                // Assistant de création : la fenêtre reste ouverte pendant la saisie des piquages
                reloadArticleList(document.body.classList.contains('ispag-wizard-on'));
            }

            // Mise à jour des IDs insérés
            if (response.data?.inserted?.length > 0) {
                response.data.inserted.forEach(item => {
                    const rows = form.querySelectorAll('.fitting-row');
                    const row = rows[item.index];
                    if (row) {
                        row.setAttribute('data-id', item.id);
                        const hiddenInput = row.querySelector('input[name="fitting[id][]"]');
                        if (hiddenInput) hiddenInput.value = item.id;
                        row.querySelectorAll('[data-fitting-id]').forEach(btn => {
                            btn.setAttribute('data-fitting-id', item.id);
                        });
                    }
                });
            }

            if (!autoSave) closeFittingsModal();
        } else {
            alert(ISPAG_TANK.text_error_saving_fitting + " !");
        }
    })
    .catch(err => {
        console.error('Error Save:', err);
        alert("Server connection error.");
    })
    .finally(() => {
        // --- RÉINITIALISATION DU BOUTON ---
        if (btnElement && !autoSave) {
            btnElement.disabled = false;
            btnElement.innerHTML = originalHtml;
        }
    });
}

document.addEventListener('click', function (e) {
    const deleteBtn = e.target.closest('.btn-delete-fitting');
    if (deleteBtn) {
        // alert('in delete');
        // const fittingId = e.target.dataset.fittingId;
        const fittingId = deleteBtn.dataset.fittingId;
        const row = e.target.closest('.fitting-row'); // adapte ce sélecteur à ta structure

        if (fittingId) {
            // appel AJAX pour supprimer en BDD
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ispag_delete_fitting',
                    fitting_id: fittingId
                })
            })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    row.remove(); // suppression du DOM
                } else {
                    alert('❌ Error while deleting');
                    console.error(response);
                }
            });
        } else {
            row.remove(); // ligne non encore enregistrée, juste côté front
        }
    }
});

// document.getElementById('technical-sheet-pdf').addEventListener('click', function () {
//     // alert('yes');
//     const url = new URL('' . admin_url('admin-ajax.php') . '');
//     url.searchParams.set('action', 'ispag_generate_technical_sheet_pdf');
//     url.searchParams.set('deal_id', getUrlParam('deal_id'));
//     url.searchParams.set('article_id', ' . intval($article->Id) . ');

//     window.open(url.toString(), '_blank');
// });

// Boutons PDF des blocs articles (fiche technique, certificat de soudure) :
// un seul gestionnaire délégué sur document, valable aussi pour les blocs rechargés dynamiquement.
document.addEventListener('click', function (event) {
    const pdfButtons = {
        '#technical-sheet-pdf': 'ispag_generate_technical_sheet_pdf',
        '#welding-certificat-pdf': 'ispag_generate_welding_certificat_pdf'
    };

    for (const selector in pdfButtons) {
        const button = event.target.closest(selector);
        if (!button) continue;

        const articleId = button.dataset.articleId;
        const dealId = button.dataset.dealId;
        if (!articleId) {
            console.error('Article ID non trouvé sur le bouton.');
            return;
        }

        const url = new URL(ISPAG_TANK.ajax_url, window.location.origin);
        url.searchParams.set('action', pdfButtons[selector]);
        if (dealId) {
            url.searchParams.set('deal_id', dealId);
        }
        url.searchParams.set('article_id', articleId);

        window.open(url.toString(), '_blank');
        return;
    }
});
