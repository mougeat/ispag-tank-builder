// Sauvegarde des données techniques de l'échangeur
function saveHeatExchangerData(articleId, is_purchase = false) {

    const exchanger = {
        type:                       $('[name="exchanger[type]"]').val(), // Nouveau champ type
        power:                      $('[name="exchanger[power]"]').val(),
        primary_temp_in:            $('[name="exchanger[primary_temp_in]"]').val(),
        primary_temp_out:           $('[name="exchanger[primary_temp_out]"]').val(),
        primary_pressure_drop:      $('[name="exchanger[primary_pressure_drop]"]').val(),
        primary_fluid:              $('[name="exchanger[primary_fluid]"]').val(),
        secondary_temp_in:          $('[name="exchanger[secondary_temp_in]"]').val(),
        secondary_temp_out:         $('[name="exchanger[secondary_temp_out]"]').val(),
        secondary_pressure_drop:    $('[name="exchanger[secondary_pressure_drop]"]').val(),
        secondary_fluid:            $('[name="exchanger[secondary_fluid]"]').val(),
    };
    
    const deal_id = getUrlParam('deal_id');
    const achat_id = getUrlParam('poid');

    console.log("Données techniques envoyées :", exchanger);

    return $.post(ISPAG_TANK.ajax_url, {
        action: 'ispag_save_exchanger_data',
        _ajax_nonce: ISPAG_TANK.nonce,
        deal_id: deal_id,
        achat_id: achat_id,
        article_id: articleId,
        is_purchase: is_purchase,
        exchanger: exchanger
    }).done(response => {
        if (!response.success) {
            console.error('Erreur sauvegarde échangeur : ', response.data || response.message);
        } else {
            console.log('Succès sauvegarde technique échangeur');
        }
    }).fail(xhr => {
        console.error('Erreur critique AJAX lors de la sauvegarde de l\'échangeur', xhr.responseText);
    });
}