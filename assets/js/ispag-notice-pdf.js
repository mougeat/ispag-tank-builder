jQuery(document).ready(function($) {
    window.generateNoticePDF = function(articleId) {
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
                    window.location.href = response.data.pdf_url;
                } else {
                    alert("Erreur : " + (response.data?.message || "Inconnu"));
                }
            },
            error: function(xhr, status, error) {
                console.error("Erreur AJAX :", xhr.status, xhr.responseText);
                alert("Erreur lors de la génération du PDF. Vérifiez la console.");
            }
        });
    };
});