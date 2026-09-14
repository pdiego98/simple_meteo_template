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

function weatherArt(int $code): array
{
    return match ($code) {
        0, 1 => ['   \\   /   ', '    .-.    ', ' - (   ) - ', "    `-'    ", '   /   \\   '],
        2 => ['   \\  /    ', ' _ /"".-.  ', '   \\_(   ).', '   /(___(__)', '            '],
        3, 45, 48 => ['            ', '    .--.    ', ' .-(    ).  ', '(___.__)__) ', '            '],
        51, 53, 55, 61, 63, 65, 80, 81, 82 => ['    .--.    ', ' .-(    ).  ', '(___.__)__) ', '  / / / /   ', ' / / / /    '],
        71, 73, 75 => ['    .--.    ', ' .-(    ).  ', '(___.__)__) ', '   *  *  *  ', '  *  *  *   '],
        95, 96, 99 => ['    .--.    ', ' .-(    ).  ', '(___.__)__) ', '  / / / /   ', '  / / / /   '],
        default => ['   .--.     ', '  (    )    ', ' (      )   ', '    `--     ', '            '],
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

function centered(string $value, int $width): string
{
    $value = substr($value, 0, $width);
    $left = (int) floor(($width - strlen($value)) / 2);
    return str_repeat(' ', max(0, $left)) . $value . str_repeat(' ', max(0, $width - $left - strlen($value)));
}

function forecastBlock(array $hourly, int $index, int $width = 28): array
{
    [$condition] = weatherInfo((int) $hourly['weather_code'][$index]);
    $art = weatherArt((int) $hourly['weather_code'][$index]);
    $temperature = rounded($hourly['temperature_2m'][$index]) . ' C';
    $wind = rounded($hourly['wind_speed_10m'][$index] ?? null) . ' km/h';
    $direction = windDirection((float) ($hourly['wind_direction_10m'][$index] ?? 0));
    $rain = number_format((float) ($hourly['precipitation'][$index] ?? 0), 1) . ' mm | ' . (int) ($hourly['precipitation_probability'][$index] ?? 0) . '%';
    $lines = [centered($condition, $width), centered($art[0], $width), centered($art[1], $width), centered($art[2], $width), centered($art[3], $width), centered($art[4], $width), centered($temperature, $width), centered($direction . ' ' . $wind, $width), centered($rain, $width)];
    return $lines;
}

function printForecastRow(array $blocks, int $line, int $width = 28): void
{
    echo '|';
    foreach ($blocks as $block) {
        echo ' ' . $block[$line] . ' |';
    }
    echo "\n";
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
        'hourly' => 'temperature_2m,precipitation_probability,precipitation,wind_speed_10m,wind_direction_10m,weather_code',
        'daily' => 'weather_code,temperature_2m_max,temperature_2m_min',
        'timezone' => 'auto',
        'forecast_days' => 3,
    ]);
    $weather = queryApi($forecastUrl);
    $current = $weather['current'];
    [$condition, $description] = weatherInfo((int) $current['weather_code']);

    header('Content-Type: text/plain; charset=us-ascii');
    header('Cache-Control: public, max-age=300');
    echo "Weather report: $name" . ($region !== '' ? ", $region" : '') . "\n\n";
    $currentArt = weatherArt((int) $current['weather_code']);
    $currentLines = [
        $currentArt[0] . '     ' . $condition,
        $currentArt[1] . '     ' . sprintf('%+d(%+d) C', round((float) $current['temperature_2m']), round((float) $current['apparent_temperature'])),
        $currentArt[2] . '     ' . windDirection((float) $current['wind_direction_10m']) . ' ' . rounded($current['wind_speed_10m']) . ' km/h',
        $currentArt[3] . '     ' . rounded($current['relative_humidity_2m']) . '% humidity',
        $currentArt[4] . '     ' . number_format((float) $current['precipitation'], 1) . ' mm',
    ];
    echo implode("\n", $currentLines) . "\n";
    echo 'Feels like: ' . rounded($current['apparent_temperature']) . " C | Pressure: " . rounded($current['surface_pressure']) . " hPa\n\n";

    $hourly = $weather['hourly'];
    $hourIndex = array_search($current['time'], $hourly['time'], true);
    $hourIndex = $hourIndex === false ? 0 : $hourIndex;
    echo "Forecast\n\n";
    $width = 28;
    $dayHours = ['06:00' => 'Morning', '12:00' => 'Noon', '18:00' => 'Evening', '00:00' => 'Night'];
    foreach ($weather['daily']['time'] as $date) {
        $indices = [];
        foreach ($dayHours as $hour => $label) {
            $wanted = $date . 'T' . $hour;
            $found = array_search($wanted, $hourly['time'], true);
            if ($found !== false) {
                $indices[] = $found;
            }
        }
        if (count($indices) !== 4) {
            continue;
        }
        echo '+' . str_repeat('-', ($width + 2) * 4 + 1) . "\n";
        echo '|';
        foreach ($dayHours as $label) {
            echo centered($label, $width + 1) . '|';
        }
        echo "\n+" . str_repeat('-', ($width + 2) * 4 + 1) . "\n";
        $blocks = array_map(fn (int $index): array => forecastBlock($hourly, $index, $width), $indices);
        for ($line = 0; $line < count($blocks[0]); $line++) {
            printForecastRow($blocks, $line, $width);
        }
        echo '+' . str_repeat('-', ($width + 2) * 4 + 1) . "\n\n";
    }
    echo "\nSource: open-meteo.com\n";
} catch (Throwable $error) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=us-ascii');
    echo "Meteo non disponibile\n";
    echo $error->getMessage() . "\n";
    exit(1);
}
