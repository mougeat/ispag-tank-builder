window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
jQuery(document).ready(function($) {
    window.generateNoticePDF = function(articleId, btnElement) {
        var $btn = $(btnElement);
        var originalHtml = $btn.html();

        // 1. Pré-ouverture de l'onglet vide pour éviter le blocage des pop-ups
        var pdfWindow = window.open('', '_blank');

        // 2. Désactivation du bouton et ajout du spinner
        $btn.prop('disabled', true).html(
            '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + ((window.ispag_texts && ispag_texts.generating) || 'Generating') + '...'
        );

        $.ajax({
            url: ispagNoticePdf.ajax_url,
            type: 'POST',
            data: {
                action: 'generate_notice_pdf',
                article_id: articleId,
                nonce: ispagNoticePdf.nonce
            },
            success: function(response) {
                if (response.success && response.data.pdf_url) {
                    // Affectation de l'URL à l'onglet ouvert
                    pdfWindow.location.href = response.data.pdf_url;
                } else {
                    pdfWindow.close(); // Ferme l'onglet vierge si erreur
                    alert(ispagT("Error: ") + (response.data || "Inconnu"));
                }
            },
            error: function(xhr, status, error) {
                pdfWindow.close(); // Ferme l'onglet vierge si erreur
                console.error("Error AJAX :", xhr.status, xhr.responseText);
                alert(ispagT("Error lors de la génération du PDF. Vérifiez la console."));
            },
            complete: function() {
                // 3. Restauration de l'état initial du bouton
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    };
});