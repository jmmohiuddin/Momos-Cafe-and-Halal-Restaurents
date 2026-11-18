#!/usr/bin/env bash
# Development web server (PHP built-in). Production uses LiteSpeed/Nginx (Doc 10 §8).
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${MOMOS_PORT:-8080}"
cd "$ROOT/.wp" && exec php -d memory_limit=512M -S "127.0.0.1:$PORT" "$ROOT/bin/router.php"
