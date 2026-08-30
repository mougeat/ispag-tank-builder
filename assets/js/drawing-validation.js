const btn_validation = document.getElementById('btn-validate-plan');

if (btn_validation) {
  btn_validation.addEventListener('click', async () => {

    const confirmed = await ispagConfirm(ispag_validation.confirmMessage + ' ?', {
        labelOk: ispag_texts.continue || "Continuer",
        labelCancel: ispag_texts.cancel || "Annuler",
        danger: true,
    });
    
    if (!confirmed) return; 
    // if (!confirm(ispag_validation.confirmMessage + ' ?')) return;

    btn_validation.disabled = true;
    btn_validation.textContent = ispag_validation.validatingMessage + '...';

    const data = {
      action: 'ispag_validate_pdf_plan',
      drawing_id: btn_validation.dataset.id,
      article_id: btn_validation.dataset.article,
      user: btn_validation.dataset.user,
      date: btn_validation.dataset.date
    };

    fetch(ispag_validation.ajax_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(data)
    })
      .then(r => r.json())
      .then(res => {
//        console.log('AJAX response:', res);
        if (res.success) {
          // alert(ispag_validation.drawingValidatedMessage + ' !');
          if (window.opener) {
            window.opener.location.reload();
            window.close();
          } else {
            location.reload();
          }
        } else {
          alert('Erreur : ' + res.data);
          btn_validation.disabled = false;
          btn_validation.textContent = '✅ ' + ispag_validation.validateDrawingButton;
        }
      });
  });
} 