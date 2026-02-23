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
    // Vérification de la disponibilité des données
    if (!restrictions.typ || !restrictions.typ[typId] || !restrictions.typ[typId].default) return;

    const defaults = restrictions.typ[typId].default;
    const allowed = restrictions.typ[typId].restrictions;

    const $supportSelect = $('select[name="tank[support]"]');
    const $materialSelect = $('select[name="tank[materiau]"]');

    // --- Support ---
    const currentSupport = String($supportSelect.val());
    if (
        defaults.Support !== undefined &&
        (!currentSupport || !allowed.Support.includes(parseInt(currentSupport)))
    ) {
        $supportSelect.val(defaults.Support).trigger('change');
    }

    // --- Matériau ---
    const currentMaterial = String($materialSelect.val());
    if (
        defaults.Material !== undefined &&
        (!currentMaterial || !allowed.Material.includes(parseInt(currentMaterial)))
    ) {
        $materialSelect.val(defaults.Material).trigger('change');
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
        $('input[name="tank[insulation]"]').val(defaults.insulation);
    }

    // --- Supplier (Gestion Datalist Dynamique) ---
    if (defaults.supplier_name !== undefined) {
        const $supplierInput = $('input[name="supplier"]');
        const $supplierDatalist = $('#supplier-list');

        // 1. On vide la datalist actuelle
        $supplierDatalist.empty();

        // 2. On s'assure que supplier_name est traité comme un tableau
        const suppliers = Array.isArray(defaults.supplier_name) ? defaults.supplier_name : [defaults.supplier_name];

        // 3. On remplit la datalist avec les nouveaux fournisseurs autorisés
        suppliers.forEach(function(name) {
            $supplierDatalist.append($('<option>').val(name));
        });

        // 4. Mise à jour de la valeur de l'input : 
        // Si la valeur actuelle est vide ou n'est plus dans la liste autorisée, on met le 1er par défaut
        if (!$supplierInput.val() || !suppliers.includes($supplierInput.val())) {
            $supplierInput.val(suppliers[0]);
        }
    }

    // Appliquer les restrictions de sélection (affichage/masquage des options)
    restrictTankOptions(typId);
}


function restrictTankOptions(typId) {
    const rules = restrictions.typ?.[typId]?.restrictions;
    if (!rules) return;

    // Support
    if (rules.Support) {
        const allowedSupports = rules.Support.map(String);
        $('select[name="tank[support]"] option').each(function () {
            const val = $(this).val();
            if (val === '' || allowedSupports.includes(val)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    }

    // Matériau
    if (rules.Material) {
        const allowedMaterials = rules.Material.map(String);
        $('select[name="tank[materiau]"] option').each(function () {
            const val = $(this).val();
            if (val === '' || allowedMaterials.includes(val)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    }

    // Isolation
    if (rules.insulation) {
        const allowedInsulation = rules.insulation.map(String);
        $('select[name="tank[insulation]"] option').each(function () {
            const val = $(this).val();
            if (val === '0') {
                $(this).show();
                return; // Passe à l'option suivante
            }
            if (val === '' || allowedInsulation.includes(val)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    } 

        // Isolation épaisseur
    if (rules.InsulationThickness) {
        const allowedInsulationThickness = rules.InsulationThickness.map(String);
        $('select[name="tank[InsulationThickness]"] option').each(function () {
            const val = $(this).val();
            if (val === '0') {
                $(this).show();
                return; // Passe à l'option suivante
            }
            if (val === '' || allowedInsulationThickness.includes(val)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    }
    
}

function updateSupplierByMaterial(selectEl) {
    const $ = jQuery;
    // 1. On récupère les IDs actuels
    const materialId = $(selectEl).val(); // ID du matériau (1, 2 ou 3)
    const typeId = $('#tank-typ').val();   // ID du type de réservoir (4, 5, 8...)

    const $supplierDatalist = $('#supplier-list');
    const $supplierInput = $('input[name="supplier"]');

    // --- PARTIE 1 : RÉPARER LES FOURNISSEURS ---
    let allSuppliers = new Set();

    // On pioche dans les deux sources du JSON pour être sûr d'avoir TML Group
    if (restrictions.material && restrictions.material[materialId]) {
        const matSupp = restrictions.material[materialId].default.supplier_name;
        matSupp.forEach(s => allSuppliers.add(s));
    }
    if (restrictions.typ && restrictions.typ[typeId]) {
        const typSupp = restrictions.typ[typeId].default.supplier_name;
        typSupp.forEach(s => allSuppliers.add(s));
    }

    $supplierDatalist.empty();
    allSuppliers.forEach(s => $supplierDatalist.append($('<option>').val(s)));

    // Si le champ est vide, on met le premier par défaut
    if (!$supplierInput.val() && allSuppliers.size > 0) {
        $supplierInput.val(Array.from(allSuppliers)[0]);
    }

    // --- PARTIE 2 : RESTAURER LES DIAMÈTRES ---
    // TRÈS IMPORTANT : Les diamètres dépendent du MATÉRIAU (Inox vs Acier)
    if (materialId) {
        updateDiameterDatalistByType(materialId); 
    }
    
    // On force le recalcul des calculs (hauteur/volume) car le fond change selon le matériau
    $('[name="tank[diameter]"]').trigger('change');
}
async function updateDiameterDatalistByType(typeId) {
    // On s'assure que les données sont prêtes
    await setIspagTankRestrictionsValue();

    // console.log('[DEBUG] Start updateDiameterDatalistByType');
    // console.log('[DEBUG] Materiau de cuve', typeId);
    // console.log('[DEBUG] Current diam', currentDiam);
    // console.log('[DEBUG] Liste des restrictions', arrayBottomHeight);

       
    // On récupère les diamètres valides depuis l'objet global
    const diameters = Object.keys(arrayBottomHeight[typeId] || {}).filter(d => arrayBottomHeight[typeId][d] > 0);

    // console.log('[DEBUG] Liste des diamètres', diameters);
    
    const selectEl = document.getElementById('tank-diameter');
    if (!selectEl) return;

    
    const currentDiam = selectEl.getAttribute('data-current-diameter');
    // console.log('Current diam', currentDiam);
    
    


    // 1. VIDAGE COMPLET DU SELECT
    selectEl.options.length = 0;

    // 2. AJOUT DE L'OPTION PAR DÉFAUT
    const defaultOption = document.createElement('option');
    defaultOption.value = "";
    defaultOption.textContent = "-- Select --";
    selectEl.appendChild(defaultOption);

    // 3. REMPLISSAGE AVEC LES NOUVELLES DONNÉES
    diameters.forEach(d => {
        const option = new Option(d + ' mm', d);
        
        // Si la valeur correspond au diamètre actuel, on la sélectionne
        if (currentDiam && d.toString() === currentDiam.toString()) {
            option.selected = true;
        }
        
        selectEl.add(option);
    });
    // console.log('[DEBUG] End updateDiameterDatalistByType');
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

    const tank = {
        type:           $('[name="tank[type]"]').val(),
        materiau:       $('[name="tank[materiau]"]').val(),
        support:        $('[name="tank[support]"]').val(),
        diameter:       $('[name="tank[diameter]"]').val(), // ou $('#tank-diameter').val()
        height:         $('[name="tank[height]"]').val(),
        volume:         $('[name="tank[volume]"]').val(),
        tipping:        $('[name="tank[tipping]"]').val(),
        max_pressure:   $('[name="tank[max_pressure]"]').val(),
        test_pressure:  $('[name="tank[test_pressure]"]').val(),
        clearance:      $('[name="tank[clearance]"]').val(),
        temperature:    $('[name="tank[temperature]"]').val(),
        insulation:     $('[name="tank[insulation]"]').val(),
        InsulationThickness: $('[name="tank[InsulationThickness]"]').val(),
        nbWelding:      $('[name="tank[nbWelding]"]').val()
    };
    
    const form = $('.ispag-edit-article-form');
    const deal_id = getUrlParam('deal_id');
    const achat_id = getUrlParam('poid');

    // On récupère les valeurs de manière sécurisée
    // const tank = {
    //     type:           form.find('select[name="tank[type]"]').val(),
    //     materiau:       form.find('select[name="tank[materiau]"]').val(),
    //     support:        form.find('select[name="tank[support]"]').val(),
    //     diameter:       form.find('select[name="tank[diameter]"]').val(),
    //     height:         form.find('input[name="tank[height]"]').val(),
    //     volume:         form.find('input[name="tank[volume]"]').val(),
    //     tipping:        form.find('input[name="tank[tipping]"]').val(),
    //     max_pressure:   form.find('input[name="tank[max_pressure]"]').val(),
    //     test_pressure:  form.find('input[name="tank[test_pressure]"]').val(),
    //     clearance:      form.find('input[name="tank[clearance]"]').val(),
    //     temperature:    form.find('input[name="tank[temperature]"]').val(),
    //     // Attention : Insulation et Welding sont souvent dans des blocs séparés
    //     insulation:     form.find('select[name="tank[insulation]"]').val(),
    //     InsulationThickness: form.find('select[name="tank[InsulationThickness]"]').val(),
    //     nbWelding:      form.find('input[name="tank[nbWelding]"]').val()
    // };

    console.log("Données envoyées au serveur :", tank); // Pour tes tests

    return $.post(ISPAG_TANK.ajax_url, {
        action: 'ispag_save_tank_data',
        _ajax_nonce: ISPAG_TANK.nonce,
        deal_id: deal_id,
        achat_id: achat_id,
        article_id: articleId,
        is_purchase: is_purchase,
        tank: tank
    }).done(response => {
        if (!response.success) {
            console.error('Erreur cuve : ', response.data);
        } else {
            console.log('Succès sauvegarde technique');
        }
    }).fail(xhr => {
        console.error('Erreur critique AJAX', xhr.responseText);
    });
}

// document.addEventListener('click', async function (e) {
//     if (e.target.classList.contains('ispag-btn-edit')) {
        
//         // 1. On attend que les données globales soient prêtes (JSON)
//         await setIspagTankRestrictionsValue();

//         let tries = 0;
//         const checkExist = setInterval(async () => {
//             const typeSelect = document.getElementById('tank-typ');
//             const materialSelect = document.getElementById('tank-material');
//             const diameterSelect = document.getElementById('tank-diameter');

//             // 2. On attend que le DOM de la modale soit injecté
//             if (typeSelect && materialSelect && diameterSelect) {
//                 clearInterval(checkExist);

//                 // On récupère les IDs via jQuery pour plus de sécurité avec les .data()
//                 const $typ = jQuery(typeSelect);
//                 const $mat = jQuery(materialSelect);
//                 const $diam = jQuery(diameterSelect);

//                 const initTypId = $typ.find(':selected').data('id') || $typ.val();
//                 const initMaterialId = $mat.find(':selected').data('id') || $mat.val();
                
//                 // On récupère la valeur sauvée en base (souvent dans l'attribut value initial)
//                 const currentDiamValue = $diam.val();

//                 console.log('[MODAL READY] Re-filling diameters for material:', initMaterialId);

//                 if (initMaterialId) {
//                     // On force le remplissage
//                     await updateDiameterDatalistByType(initMaterialId, currentDiamValue);
                    
//                     // On synchronise les autres champs par sécurité
//                     updateTankDefaults(initTypId);
//                 }
//             }

//             tries++;
//             if (tries > 20) clearInterval(checkExist); // On attend jusqu'à 2 secondes
//         }, 100);
//     }
// });

jQuery(document).ready(function($) {
    // Délégation sur un parent permanent
    $(document).on('click', '#open-tank-fittings-modal', function() {
        const articleId = $(this).data('article-id');
        $('#tank-fittings-modal').fadeIn();

        // Met à jour le lien 3D
        $('#tank-fittings-modal a.display-tank-3d').attr('href', '/rendu-3d/?article_id=' + articleId);

        $('#fittings-form').html('<p>Chargement...</p>');

        $.post(ajaxurl, {
            action: 'ispag_load_fittings_form',
            article_id: articleId
        }, function(response) {
            // console.log(response);
            if (response.success) {
                $('#fittings-form').html(response.data['html']);
                $('#ispag-modal-svg').html(response.data['svg']);
            } else {
                $('#fittings-form').html('<p>Erreur de chargement</p>');
            }
        });
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

    // 1. GESTION DES AJOUTS (via ID ou closest pour gérer l'icône interne)
    const addFittingBtn = e.target.closest('#add-fitting-row');
    const addWeldingBtn = e.target.closest('#add-welding-row');

    if (addFittingBtn) {
        const template = document.getElementById('fitting-row-template');
        form.appendChild(template.content.cloneNode(true));
        return; // On sort pour éviter de traiter les autres conditions
    }
    
    if (addWeldingBtn) {
        const template = document.getElementById('welding-row-template');
        form.appendChild(template.content.cloneNode(true));
        return;
    }

    // 2. GESTION DE LA SUPPRESSION
    const removeBtn = e.target.closest('.btn-remove, .btn-delete-fitting');
    if (removeBtn) {
        removeBtn.closest('.fitting-row').remove();
        return;
    }

    // 3. GESTION DE LA DUPLICATION
    const duplicateBtn = e.target.closest('.btn-duplicate');
    if (duplicateBtn) {
        const row = duplicateBtn.closest('.fitting-row');
        const clone = row.cloneNode(true);

        // --- Synchronisation des valeurs (Select ET Input) ---
        // Les selects
        const originalSelects = row.querySelectorAll('select');
        const clonedSelects = clone.querySelectorAll('select');
        originalSelects.forEach((select, i) => {
            clonedSelects[i].value = select.value;
        });

        // Les inputs (text, number, etc.)
        const originalInputs = row.querySelectorAll('input:not([type="hidden"])');
        const clonedInputs = clone.querySelectorAll('input:not([type="hidden"])');
        originalInputs.forEach((input, i) => {
            clonedInputs[i].value = input.value;
        });

        // --- Reset des identifiants pour la nouvelle ligne ---
        const hiddenInput = clone.querySelector('input[name="fitting[id][]"]');
        if (hiddenInput) {
            hiddenInput.value = '0';
        }

        clone.dataset.id = '0';

        // Reset data-attributes des boutons dans le clone
        clone.querySelectorAll('.btn-duplicate, .btn-delete-fitting').forEach(btn => {
            btn.dataset.fittingId = '0';
        });

        form.appendChild(clone);
    }
});

function closeFittingsModal(){
    $('#tank-fittings-modal').fadeOut();
}

const saveBtn = document.getElementById('ispag-btn-save-tank-fittings');
if (saveBtn) {
    saveBtn.addEventListener('click', function () {
        saveFittings(false); // sauvegarde manuelle, on ferme la modale
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

function saveFittings(autoSave = false) {
    const form = document.getElementById('fittings-form');
    const formData = new FormData(form);
    const articleId = document.querySelector('input[name="article_id"]').value;

    formData.append('action', 'ispag_save_fittings');
    formData.append('article_id', articleId); 

    // console.log('--- Contenu de FormData (méthode for...of) ---');
 
for (const pair of formData.entries()) {
    // pair[0] est la clé (le nom du champ)
    // pair[1] est la valeur du champ
    // console.log(pair[0] + ': ' + pair[1]);
}
 
    fetch(ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(response => {
        // console.log('[AutoSave]', response);

        if (response.success) {
            // Affiche le SVG une seule fois
            if (response.data?.drawing && $("#ispag-modal-svg").length) {
                // console.log('🔧 SVG reçu :', response.data.drawing);
                $("#ispag-modal-svg").html(response.data.drawing);
                reloadArticleList();
            }

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
        } else{
            alert(ISPAG_TANK.text_error_saving_fitting + " !");
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
                    alert('❌ Erreur lors de la suppression');
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

document.addEventListener('click', function (event) {
    // Vérifie si l'élément cliqué est un bouton avec l'ID spécifique ou une classe pertinente
    if (event.target.matches('#technical-sheet-pdf') || event.target.closest('#technical-sheet-pdf')) {
        const button = event.target.closest('#technical-sheet-pdf'); // Trouve le bouton parent s'il y a un enfant cliqué
        const articleId = button.dataset.articleId;
        const dealId = button.dataset.dealId;

        if (articleId) {
            const url = new URL(admin_url('admin-ajax.php') );
            url.searchParams.set('action', 'ispag_generate_technical_sheet_pdf');
            if (dealId) { // Ajoute deal_id seulement s'il est présent
                url.searchParams.set('deal_id', dealId);
            }
            url.searchParams.set('article_id', articleId);

            window.open(url.toString(), '_blank');
        } else {
            console.error('Article ID non trouvé sur le bouton.');
        }
    }
});

document.addEventListener('click', function (event) {
    // Vérifie si l'élément cliqué est un bouton avec l'ID spécifique ou une classe pertinente
    if (event.target.matches('#welding-certificat-pdf') || event.target.closest('#welding-certificat-pdf')) {
        const button = event.target.closest('#welding-certificat-pdf'); // Trouve le bouton parent s'il y a un enfant cliqué
        const articleId = button.dataset.articleId;
        const dealId = button.dataset.dealId;

        if (articleId) {
            
            const url = new URL(admin_url('admin-ajax.php') );
            url.searchParams.set('action', 'ispag_generate_welding_certificat_pdf');
            if (dealId) { // Ajoute deal_id seulement s'il est présent
                url.searchParams.set('deal_id', dealId);
            }
            url.searchParams.set('article_id', articleId);

            window.open(url.toString(), '_blank');
        } else {
            console.error('Article ID non trouvé sur le bouton.');
        }
    }
});

// // Fonction getUrlParam si elle est toujours nécessaire pour d'autres contextes, sinon elle peut être retirée si ce script est le seul usage de deal_id
// function getUrlParam(paramName) {
//     const urlParams = new URLSearchParams(window.location.search);
//     return urlParams.get(paramName);
// }