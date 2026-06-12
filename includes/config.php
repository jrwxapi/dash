<?php
// Database connection
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'dashboard');
define('DB_USER', 'dashboard');
define('DB_PASS', 'dashboard_pass');

// App
define('APP_NAME', 'Personal Intelligence Dashboard');

// Poller intervals (seconds). cron/poll.php runs every minute and fires each
// poller whose interval has elapsed since its last successful run.
define('POLL_INTERVALS', [
    'quotes'  => 300,    // Yahoo Finance quotes — 5 min
    'truth'   => 600,    // Truth Social mirror — 10 min
    'weather' => 900,    // Open-Meteo — 15 min
    'news'    => 1800,   // RSS feeds — 30 min
    'ai'      => 900,    // Ollama digest check — 15 min (skips fresh digests)
]);

// How old an article/post can be before it's dropped from the feed panels.
define('NEWS_MAX_AGE_HOURS', 48);
define('TRUTH_MAX_AGE_HOURS', 24);

// Don't regenerate an AI digest younger than this (seconds).
define('AI_DIGEST_MIN_INTERVAL', 1800);

// All timestamps stored/served as UTC; the frontend renders them in the
// time zone configured on the admin page ('timezone' setting, default ET).
date_default_timezone_set('UTC');
