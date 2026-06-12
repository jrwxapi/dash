<?php
/**
 * Weather poller — Open-Meteo (free, no API key).
 * Port of workers/weather_poller.py. Location comes from the settings table
 * (editable in admin.php). Writes kv key: weather:current
 */

const WMO_CONDITIONS = [
    0 => 'Clear sky', 1 => 'Mainly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
    45 => 'Fog', 48 => 'Icy fog',
    51 => 'Light drizzle', 53 => 'Drizzle', 55 => 'Heavy drizzle',
    61 => 'Light rain', 63 => 'Rain', 65 => 'Heavy rain',
    71 => 'Light snow', 73 => 'Snow', 75 => 'Heavy snow', 77 => 'Snow grains',
    80 => 'Rain showers', 81 => 'Heavy showers', 82 => 'Violent showers',
    85 => 'Snow showers', 86 => 'Heavy snow showers',
    95 => 'Thunderstorm', 96 => 'Thunderstorm w/ hail', 99 => 'Thunderstorm w/ heavy hail',
];

const WIND_DIRS = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];

function wx_c_to_f(float $c): float    { return round($c * 9 / 5 + 32, 1); }
function wx_kmh_to_mph(float $k): float { return round($k * 0.621371, 1); }
function wx_dir(float $deg): string     { return WIND_DIRS[(int)(($deg + 11.25) / 22.5) % 16]; }

function poll_weather(): string {
    $lat = setting('weather_lat', '40.7128');
    $lon = setting('weather_lon', '-74.0060');
    $location = setting('weather_location', 'New York, NY');

    $url = 'https://api.open-meteo.com/v1/forecast'
         . "?latitude=$lat&longitude=$lon"
         . '&current=temperature_2m,apparent_temperature,weather_code,'
         . 'relative_humidity_2m,wind_speed_10m,wind_direction_10m,visibility'
         . '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max'
         . '&temperature_unit=celsius&wind_speed_unit=kmh&forecast_days=7'
         . '&timezone=America%2FNew_York';

    $data = http_get_json($url);
    if (!$data || !isset($data['current'], $data['daily'])) {
        throw new RuntimeException('Open-Meteo request failed');
    }

    $cur = $data['current'];
    $daily = $data['daily'];

    $current = [
        'location'         => $location,
        'temperature_f'    => wx_c_to_f($cur['temperature_2m']),
        'feels_like_f'     => wx_c_to_f($cur['apparent_temperature']),
        'condition'        => WMO_CONDITIONS[$cur['weather_code']] ?? 'Unknown',
        'humidity'         => $cur['relative_humidity_2m'],
        'wind_mph'         => wx_kmh_to_mph($cur['wind_speed_10m']),
        'wind_direction'   => wx_dir($cur['wind_direction_10m']),
        'visibility_miles' => round((($cur['visibility'] ?? 10000) / 1000) * 0.621371, 1),
        'updated_at'       => iso_now(),
    ];

    $forecast = [];
    foreach ($daily['time'] as $i => $date) {
        $forecast[] = [
            'date'                 => $date,
            'high_f'               => wx_c_to_f($daily['temperature_2m_max'][$i]),
            'low_f'                => wx_c_to_f($daily['temperature_2m_min'][$i]),
            'condition'            => WMO_CONDITIONS[$daily['weather_code'][$i]] ?? 'Unknown',
            'precipitation_chance' => $daily['precipitation_probability_max'][$i] ?? 0,
        ];
    }

    kv_set('weather:current', ['current' => $current, 'forecast' => $forecast],
           POLL_INTERVALS['weather'] * 4);

    return "Weather updated: {$current['temperature_f']}°F {$current['condition']}";
}
