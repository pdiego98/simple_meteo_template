const DEFAULT_LOCATION = { name: 'Arezzo', latitude: 43.47, longitude: 11.85, region: 'Toscana, Italia' };
const weatherLabels = { 0: ['Sereno', '☀'], 1: ['Prevalentemente sereno', '◒'], 2: ['Parzialmente nuvoloso', '◐'], 3: ['Coperto', '☁'], 45: ['Nebbia', '≋'], 48: ['Nebbia con brina', '≋'], 51: ['Pioviggine', '╱'], 53: ['Pioviggine', '╱'], 55: ['Pioviggine intensa', '╱'], 61: ['Pioggia debole', '☂'], 63: ['Pioggia', '☂'], 65: ['Pioggia intensa', '☂'], 71: ['Neve debole', '✳'], 73: ['Neve', '✳'], 75: ['Neve intensa', '✳'], 80: ['Rovesci', '☂'], 81: ['Rovesci', '☂'], 82: ['Rovesci intensi', '☂'], 95: ['Temporale', 'ϟ'], 96: ['Temporale con grandine', 'ϟ'], 99: ['Temporale con grandine', 'ϟ'] };
const $ = (selector) => document.querySelector(selector);
let activeLocation = DEFAULT_LOCATION;

function weatherInfo(code) { return weatherLabels[code] || ['Condizioni variabili', '◌']; }
function showToast(message) { const toast = $('#toast'); toast.textContent = message; toast.classList.add('is-visible'); setTimeout(() => toast.classList.remove('is-visible'), 3200); }
function formatNumber(value) { return Number.isFinite(value) ? Math.round(value) : '--'; }
function formatHour(value) { return new Date(value).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }); }
function formatDay(value, index) { return index === 0 ? 'oggi' : new Date(value).toLocaleDateString('it-IT', { weekday: 'short' }).replace('.', ''); }
function windDirection(degrees) { const directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW']; return directions[Math.round(degrees / 45) % 8] || '--'; }

function applyLocation(location) {
  activeLocation = location;
  $('#location-name').textContent = location.name;
  $('#location-region').innerHTML = `${location.region || 'Località selezionata'} <span class="coordinates">${location.latitude.toFixed(2)}° N / ${location.longitude.toFixed(2)}° E</span>`;
  loadWeather();
}

async function loadWeather() {
  const params = new URLSearchParams({ latitude: activeLocation.latitude, longitude: activeLocation.longitude, current: 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,wind_speed_10m,wind_direction_10m,surface_pressure,weather_code', hourly: 'temperature_2m,precipitation_probability,weather_code', daily: 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max', timezone: 'auto', forecast_days: '7' });
  try {
    const response = await fetch(`https://api.open-meteo.com/v1/forecast?${params}`);
    if (!response.ok) throw new Error('API error');
    const data = await response.json();
    renderWeather(data);
  } catch (error) {
    showToast('Impossibile caricare i dati meteo. Riprova tra poco.');
    $('#condition-label').textContent = 'Dati non disponibili';
  }
}

function renderWeather(data) {
  const current = data.current;
  const [condition, icon] = weatherInfo(current.weather_code);
  $('#condition-label').textContent = condition;
  $('#condition-icon').textContent = icon;
  $('#temperature-value').textContent = formatNumber(current.temperature_2m);
  $('#feels-like-value').textContent = `${formatNumber(current.apparent_temperature)}°`;
  $('#humidity-value').textContent = `${formatNumber(current.relative_humidity_2m)}%`;
  $('#wind-value').innerHTML = `${formatNumber(current.wind_speed_10m)} <small>km/h</small>`;
  $('#wind-direction').textContent = `${windDirection(current.wind_direction_10m)} / direzione vento`;
  $('#rain-value').innerHTML = `${Number(current.precipitation || 0).toFixed(1)} <small>mm</small>`;
  $('#pressure-value').innerHTML = `${formatNumber(current.surface_pressure)} <small>hPa</small>`;
  $('#local-time').textContent = formatHour(current.time);
  $('#updated-label').textContent = `aggiornato ${formatHour(current.time)}`;
  renderHourly(data.hourly, current.time);
  renderDaily(data.daily);
}

function renderHourly(hourly, currentTime) {
  const currentIndex = hourly.time.findIndex((time) => time >= currentTime);
  const start = Math.max(0, currentIndex);
  $('#hourly-list').innerHTML = hourly.time.slice(start, start + 8).map((time, index) => { const [label, icon] = weatherInfo(hourly.weather_code[start + index]); const rain = hourly.precipitation_probability[start + index] || 0; return `<article class="hour ${index === 0 ? 'is-now' : ''}"><div class="hour-time">${index === 0 ? 'ora' : formatHour(time)}</div><div class="hour-icon" aria-label="${label}">${icon}</div><div class="hour-temp">${formatNumber(hourly.temperature_2m[start + index])}°</div><div class="hour-rain">${rain}% pioggia</div></article>`; }).join('');
}
function renderDaily(daily) { $('#daily-list').innerHTML = daily.time.map((date, index) => { const [label, icon] = weatherInfo(daily.weather_code[index]); return `<article class="day"><div class="day-name">${formatDay(date, index)}</div><div class="day-icon" aria-label="${label}">${icon}</div><div class="day-condition">${label}</div><div class="day-temps">${formatNumber(daily.temperature_2m_max[index])}° <span>${formatNumber(daily.temperature_2m_min[index])}°</span></div></article>`; }).join(''); }

async function searchLocations() {
  const query = $('#location-input').value.trim(); if (query.length < 2) return;
  const results = $('#search-results'); results.hidden = false; results.innerHTML = '<div class="result">ricerca in corso...</div>';
  try { const response = await fetch(`https://geocoding-api.open-meteo.com/v1/search?name=${encodeURIComponent(query)}&count=5&language=it&format=json`); const data = await response.json(); if (!data.results?.length) { results.innerHTML = '<div class="result">nessuna località trovata</div>'; return; } results.innerHTML = data.results.map((result, index) => `<button class="result" type="button" data-result-index="${index}"><strong>${result.name}</strong><small>${[result.admin1, result.country].filter(Boolean).join(', ')}</small></button>`).join(''); results.querySelectorAll('.result').forEach((button) => button.addEventListener('click', () => { applyLocation(data.results[button.dataset.resultIndex]); results.hidden = true; $('#location-input').value = ''; })); } catch (error) { results.innerHTML = '<div class="result">servizio di ricerca non disponibile</div>'; }
}

$('#search-button').addEventListener('click', searchLocations);
$('#location-input').addEventListener('keydown', (event) => { if (event.key === 'Enter') searchLocations(); });
document.addEventListener('click', (event) => { if (!event.target.closest('.search-box')) $('#search-results').hidden = true; });
$('#locate-button').addEventListener('click', () => { if (!navigator.geolocation) { showToast('Geolocalizzazione non supportata dal browser.'); return; } navigator.geolocation.getCurrentPosition(async (position) => { const { latitude, longitude } = position.coords; applyLocation({ name: 'La tua posizione', latitude, longitude, region: 'coordinate GPS' }); }, () => showToast('Permesso di localizzazione non disponibile.')); });

const query = new URLSearchParams(window.location.search);
if (query.get('embed') === '1') document.body.classList.add('is-embed');
const queryLocation = { name: query.get('name'), latitude: Number(query.get('lat')), longitude: Number(query.get('lon')), region: query.get('region') || 'località personalizzata' };
if (queryLocation.name && Number.isFinite(queryLocation.latitude) && Number.isFinite(queryLocation.longitude)) activeLocation = queryLocation;
applyLocation(activeLocation);
