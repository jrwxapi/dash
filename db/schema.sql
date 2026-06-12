-- Personal dashboard schema.
-- kv replaces the Redis cache from the original Docker stack: every poller
-- writes its payload here and the frontend reads it back via public/api.php.

CREATE TABLE IF NOT EXISTS kv (
    key        TEXT PRIMARY KEY,
    value      JSONB NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);

-- Manual portfolio (replaces the Robinhood integration): enter positions in
-- the admin page; quotes are fetched from Yahoo Finance by cron/pollers/quotes.php.
CREATE TABLE IF NOT EXISTS holdings (
    id       SERIAL PRIMARY KEY,
    ticker   TEXT NOT NULL UNIQUE,
    name     TEXT NOT NULL DEFAULT '',
    quantity NUMERIC NOT NULL DEFAULT 0,
    avg_cost NUMERIC NOT NULL DEFAULT 0
);

-- Account-value samples recorded each quotes poll; feeds the 5-day sparkline.
CREATE TABLE IF NOT EXISTS equity_history (
    id          SERIAL PRIMARY KEY,
    equity      NUMERIC NOT NULL,
    recorded_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO settings (key, value) VALUES
    ('display_name',      'Operator'),
    ('weather_lat',       '40.7128'),
    ('weather_lon',       '-74.0060'),
    ('weather_location',  'New York, NY'),
    ('espp_ticker',       ''),
    ('espp_name',         'ESPP Holding'),
    ('espp_shares',       '0'),
    ('espp_cost_basis',   '0'),
    ('ollama_host',       'http://127.0.0.1:11434'),
    ('ollama_model',      'phi3:mini'),
    ('ollama_json_model', 'gemma2:2b')
ON CONFLICT (key) DO NOTHING;
