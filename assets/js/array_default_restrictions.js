var arrayBottomHeight = {};
var restrictions = {};
var conceptionId;

let isDataLoaded = false; // Variable globale

async function setIspagTankRestrictionsValue() {
  if (isDataLoaded) return;

  try {
    const response = await fetch(ISPAG_TANK.jsonUrl);
    if (!response.ok) throw new Error('JSON loading error');

    const data = await response.json();
    arrayBottomHeight = data.arrayBottomHeight;
    restrictions = data.restrictions;

    // 👇 Déclencher un événement personnalisé quand les données sont prêtes
    isDataLoaded = true;
    jQuery(document).trigger('ispag:restrictions_loaded');
  } catch (error) {
    console.error('Error lors du chargement des données:', error);
  }
}