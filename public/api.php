<?php
/**
 * Read-only JSON endpoint for the frontend.
 *   api.php?action=state   -> { channels: {<channel>: <payload>|null, ...}, generated_at }
 *   api.php?action=config  -> { display_name, weather_location }
 * Replaces the original's WebSocket snapshot/push: js/api.js polls ?action=state
 * and emits each channel to the panel renderers, only when the payload changed.
 */
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

const STATE_CHANNELS = [
    'portfolio:summary',
    'portfolio:holdings',
    'portfolio:movers',
    'portfolio:history',
    'espp:holdings',
    'news:global',
    'news:cyber',
    'truth:posts',
    'weather:current',
    'ai:briefing',
    'ai:global',
    'ai:cyber',
    'ai:portfolio',
];

$action = $_GET['action'] ?? 'state';

try {
    switch ($action) {
        case 'state':
            $channels = [];
            $st = db()->prepare(
                'SELECT key, value FROM kv
                 WHERE key = ANY(:keys) AND (expires_at IS NULL OR expires_at > now())'
            );
            $st->execute([':keys' => '{' . implode(',', array_map(
                fn($k) => '"' . $k . '"', STATE_CHANNELS)) . '}']);
            foreach ($st->fetchAll() as $row) {
                $channels[$row['key']] = json_decode($row['value'], true);
            }
            foreach (STATE_CHANNELS as $k) {
                if (!array_key_exists($k, $channels)) $channels[$k] = null;
            }
            echo json_encode(['channels' => $channels, 'generated_at' => iso_now()]);
            break;

        case 'config':
            echo json_encode([
                'display_name'     => setting('display_name', 'Operator'),
                'weather_location' => setting('weather_location', ''),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server error']);
}
