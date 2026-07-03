jQuery(document).ready(function($) {
    window.generateNoticePDF = function(articleId, lang) {
        $.ajax({
            url: ispagNoticePdf.ajax_url,
            type: 'POST',
            data: {
                action: 'generate_notice_pdf',
                article_id: articleId,
                lang: lang,
                nonce: ispagNoticePdf.nonce
            },
            beforeSend: function() {
                console.log("Génération de la notice en cours...");
            },
            success: function(response) {
                if (response.success && response.data.pdf_url) {
                    // Rediriger vers l'URL du PDF pour le télécharger
                    window.location.href = response.data.pdf_url;
                } else {
                    console.error("Erreur :", response);
                    alert("Erreur lors de la génération du PDF : " + (response.data?.message || "Inconnu"));
                }
            },
            error: function(xhr, status, error) {
                console.error("Erreur AJAX :", xhr.status, xhr.responseText);
                alert("Erreur lors de la génération du PDF. Vérifiez la console.");
            }
        });
    };
});