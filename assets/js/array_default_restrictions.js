var arrayBottomHeight = {};
var restrictions = {};
var conceptionId;

let isDataLoaded = false; // Variable globale

async function setIspagTankRestrictionsValue() {
  if (isDataLoaded) return;

  try {
    const response = await fetch(ISPAG_TANK.jsonUrl);
    if (!response.ok) throw new Error('Erreur chargement JSON');

    const data = await response.json();
    arrayBottomHeight = data.arrayBottomHeight;
    restrictions      = data.restrictions;

    // console.log('setIspagTankRestrictionsValue', restrictions);
    // console.log('arrayBottomHeight', arrayBottomHeight);

    // 👇 déclenche l’événement quand c’est prêt
    isDataLoaded = true;
    jQuery(document).trigger('restrictions:ready');
  } catch (error) {
    console.error('Erreur lors du chargement des données:', error);
  }
}
