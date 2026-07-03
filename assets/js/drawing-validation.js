const btn = document.getElementById('btn-validate-plan');

if (btn) {
  btn.addEventListener('click', () => {
    if (!confirm(ispag_validation.confirmMessage + ' ?')) return;

    btn.disabled = true;
    btn.textContent = ispag_validation.validatingMessage + '...';

    const data = {
      action: 'ispag_validate_pdf_plan',
      drawing_id: btn.dataset.id,
      article_id: btn.dataset.article,
      user: btn.dataset.user,
      date: btn.dataset.date
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
          btn.disabled = false;
          btn.textContent = '✅ ' + ispag_validation.validateDrawingButton;
        }
      });
  });
}