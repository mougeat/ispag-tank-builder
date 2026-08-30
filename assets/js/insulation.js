function renderInsulationForm(articleId){
    const insulation = {
        type:           $('[name="tank[type]"]').val(),
        diameter:       $('[name="tank[diameter]"]').val(), // ou $('#tank-diameter').val()
        height:         $('[name="tank[height]"]').val(),
        volume:         $('[name="tank[volume]"]').val(),
        temperature:    $('[name="tank[temperature]"]').val(),
    };

    console.log("Données envoyées au serveur :", insulation); // Pour tes tests
    fetch(ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: action,
            type_id: type,
            articleId: articleId || '',
            insulation: insulation || ''
        })
    })
    .then(res => res.text())
    .then(html => {

        console.log(html);

    });

}

// Sauvegarde des données techniques de l'isoaltion
// function saveTankData(articleId, is_purchase = false) {

//     saveHeatExchangerData(articleId, is_purchase);

//     const tank = {
//         type:           $('[name="tank[type]"]').val(),
//         materiau:       $('[name="tank[materiau]"]').val(),
//         support:        $('[name="tank[support]"]').val(),
//         diameter:       $('[name="tank[diameter]"]').val(), // ou $('#tank-diameter').val()
//         height:         $('[name="tank[height]"]').val(),
//         volume:         $('[name="tank[volume]"]').val(),
//         tipping:        $('[name="tank[tipping]"]').val(),
//         max_pressure:   $('[name="tank[max_pressure]"]').val(),
//         test_pressure:  $('[name="tank[test_pressure]"]').val(),
//         clearance:      $('[name="tank[clearance]"]').val(),
//         temperature:    $('[name="tank[temperature]"]').val(),
//         insulation:     $('[name="tank[insulation]"]').val(),
//         insulationCover: $('[name="tank[insulationCover]"]').val(),
//         InsulationThickness: $('[name="tank[InsulationThickness]"]').val(),
//         nbWelding:      $('[name="tank[nbWelding]"]').val()
//     };
    
//     const form = $('.ispag-edit-article-form');
//     const deal_id = getUrlParam('deal_id');
//     const achat_id = getUrlParam('poid');



//     console.log("Données envoyées au serveur :", tank); // Pour tes tests

//     return $.post(ISPAG_TANK.ajax_url, {
//         action: 'ispag_save_tank_data',
//         _ajax_nonce: ISPAG_TANK.nonce,
//         deal_id: deal_id,
//         achat_id: achat_id,
//         article_id: articleId,
//         is_purchase: is_purchase,
//         tank: tank
//     }).done(response => {
//         if (!response.success) {
//             console.error('Erreur cuve : ', response.data);
//         } else {
//             console.log('Succès sauvegarde technique', response.data);
//         }
//     }).fail(xhr => {
//         console.error('Erreur critique AJAX', xhr.responseText);
//     });
// }
