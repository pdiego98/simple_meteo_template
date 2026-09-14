<?php

declare(strict_types=1);

const DEFAULT_NAME = 'Arezzo';
const DEFAULT_LATITUDE = 43.47;
const DEFAULT_LONGITUDE = 11.85;

function queryApi(string $url): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'meteo.local/1.0',
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($body === false || $status >= 400) {
        throw new RuntimeException($error ?: "API HTTP $status");
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Risposta API non valida');
    }

    return $data;
}

function parameter(string $key, string $default = ''): string
{
    return trim((string) ($_GET[$key] ?? $default));
}

function numberParameter(string $key, float $default): float
{
    $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_FLOAT);
    return $value === false ? $default : (float) $value;
}

function weatherInfo(int $code): array
{
    return match ($code) {
        0 => ['Sereno', 'clear'],
        1, 2 => ['Parzialmente sereno', 'partly cloudy'],
        3 => ['Coperto', 'overcast'],
        45, 48 => ['Nebbia', 'fog'],
        51, 53, 55 => ['Pioviggine', 'drizzle'],
        61, 63, 65, 80, 81, 82 => ['Pioggia', 'rain'],
        71, 73, 75 => ['Neve', 'snow'],
        95, 96, 99 => ['Temporale', 'storm'],
        default => ['Variabile', 'variable'],
    };
}

function windDirection(float $degrees): string
{
    $directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
    return $directions[(int) round($degrees / 45) % 8] ?? '--';
}

function rounded(mixed $value): string
{
    return is_numeric($value) ? (string) round((float) $value) : '--';
}

function asciiLine(string $label, string $value): string
{
    return sprintf("  %-14s %s\n", $label, $value);
}

try {
    $name = parameter('name', parameter('city', DEFAULT_NAME));
    $latitude = numberParameter('lat', DEFAULT_LATITUDE);
    $longitude = numberParameter('lon', DEFAULT_LONGITUDE);
    $region = parameter('region', '');

    if (isset($_GET['city']) || (isset($_GET['name']) && !isset($_GET['lat']))) {
        $geocodingUrl = 'https://geocoding-api.open-meteo.com/v1/search?' . http_build_query([
            'name' => $name,
            'count' => 1,
            'language' => 'en',
            'format' => 'json',
        ]);
        $geocoding = queryApi($geocodingUrl);
        if (empty($geocoding['results'][0])) {
            throw new RuntimeException("Localita non trovata: $name");
        }
        $place = $geocoding['results'][0];
        $name = (string) ($place['name'] ?? $name);
        $region = implode(', ', array_filter([(string) ($place['admin1'] ?? ''), (string) ($place['country'] ?? '')]));
        $latitude = (float) $place['latitude'];
        $longitude = (float) $place['longitude'];
    }

    $forecastUrl = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude' => $latitude,
        'longitude' => $longitude,
        'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,wind_speed_10m,wind_direction_10m,surface_pressure,weather_code',
        'hourly' => 'temperature_2m,precipitation_probability,weather_code',
        'daily' => 'weather_code,temperature_2m_max,temperature_2m_min',
        'timezone' => 'auto',
        'forecast_days' => 3,
    ]);
    $weather = queryApi($forecastUrl);
    $current = $weather['current'];
    [$condition, $description] = weatherInfo((int) $current['weather_code']);

    header('Content-Type: text/plain; charset=us-ascii');
    header('Cache-Control: public, max-age=300');
    echo "Meteo per $name" . ($region !== '' ? ", $region" : '') . "\n";
    echo str_repeat('-', 44) . "\n";
    echo asciiLine('Condizioni:', "$condition ($description)");
    echo asciiLine('Temperatura:', rounded($current['temperature_2m']) . " C");
    echo asciiLine('Percepita:', rounded($current['apparent_temperature']) . " C");
    echo asciiLine('Umidita:', rounded($current['relative_humidity_2m']) . "%");
    echo asciiLine('Vento:', rounded($current['wind_speed_10m']) . " km/h " . windDirection((float) $current['wind_direction_10m']));
    echo asciiLine('Precipitazioni:', number_format((float) $current['precipitation'], 1) . " mm");
    echo asciiLine('Pressione:', rounded($current['surface_pressure']) . " hPa");
    echo "\nPrevisioni orarie\n" . str_repeat('-', 44) . "\n";

    $hourly = $weather['hourly'];
    $hourIndex = array_search($current['time'], $hourly['time'], true);
    $hourIndex = $hourIndex === false ? 0 : $hourIndex;
    for ($index = $hourIndex; $index < min($hourIndex + 8, count($hourly['time'])); $index++) {
        [$hourCondition] = weatherInfo((int) $hourly['weather_code'][$index]);
        $rain = (int) ($hourly['precipitation_probability'][$index] ?? 0);
        echo sprintf("  %s  %3s C  %-20s %2d%% rain\n", substr($hourly['time'][$index], 11, 5), rounded($hourly['temperature_2m'][$index]), $hourCondition, $rain);
    }

    echo "\nPrevisioni giornaliere\n" . str_repeat('-', 44) . "\n";
    foreach ($weather['daily']['time'] as $index => $date) {
        [$dayCondition] = weatherInfo((int) $weather['daily']['weather_code'][$index]);
        echo sprintf("  %s  %3s / %3s C  %s\n", $date, rounded($weather['daily']['temperature_2m_max'][$index]), rounded($weather['daily']['temperature_2m_min'][$index]), $dayCondition);
    }
    echo "\nSource: open-meteo.com\n";
} catch (Throwable $error) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=us-ascii');
    echo "Meteo non disponibile\n";
    echo $error->getMessage() . "\n";
    exit(1);
}
