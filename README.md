# meteo.local

Prima struttura statica ispirata all'immediatezza di wttr.in, con dati da Open-Meteo.

## Avvio

Aprire `index.html` in un browser oppure servire la cartella con un web server statico. Le chiamate fetch verso Open-Meteo funzionano normalmente da un server locale o da hosting HTTPS.

## Embed

La pagina supporta una vista compatta:

```html
<iframe
  src="https://tuo-dominio.example/?embed=1&name=Arezzo&lat=43.47&lon=11.85&region=Toscana"
  title="Meteo di Arezzo"
  width="100%"
  height="420"
  loading="lazy"
  style="border:0"
></iframe>
```

Parametri disponibili: `embed=1`, `name`, `lat`, `lon`, `region`.

## Output ASCII da curl

Per ottenere un report testuale simile a `wttr.in`, pubblicare anche `weather.php` su un server PHP con estensione cURL abilitata:

```bash
curl "https://tuo-dominio.example/weather.php?city=Arezzo"
curl "https://tuo-dominio.example/weather.php?name=Firenze&lat=43.77&lon=11.25&region=Toscana"
```

`city` o `name` senza coordinate usa il geocoding gratuito di Open-Meteo. Con `lat` e `lon` la richiesta va direttamente alle previsioni. L'endpoint restituisce `text/plain` in ASCII, con condizioni correnti, prossime ore e tre giorni di previsione.

Avvio locale, dalla cartella del progetto:

```bash
php -S localhost:8080
curl "http://localhost:8080/weather.php?city=Arezzo"
```
