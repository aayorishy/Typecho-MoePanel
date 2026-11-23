#!/usr/bin/env bash
#
# MoePanel Bot watchdog for BT / cron.
# Add to BT cron: every 1 minute
#   /bin/bash /www/wwwroot/ayko-blog/usr/plugins/MoePanel/bin/watchdog.sh
#
set -u

ROOT="${MOEPANEL_ROOT:-/www/wwwroot/ayko-blog}"
PLUGIN="${MOEPANEL_PLUGIN:-$ROOT/usr/plugins/MoePanel}"
RUN_DIR="${MOEPANEL_RUN_DIR:-/tmp/moepanel-kook-bot}"
PID_FILE="$RUN_DIR/bot.pid"
HEARTBEAT_FILE="$RUN_DIR/heartbeat"
LOG_FILE="$RUN_DIR/bot.log"
MAX_HEARTBEAT_AGE="${MOEPANEL_HEARTBEAT_AGE:-90}"

mkdir -p "$RUN_DIR"

find_php() {
    if [ -n "${MOEPANEL_PHP:-}" ] && [ -x "$MOEPANEL_PHP" ]; then
        echo "$MOEPANEL_PHP"
        return
    fi

    for candidate in /www/server/php/*/bin/php; do
        if [ -x "$candidate" ]; then
            echo "$candidate"
            return
        fi
    done

    command -v php 2>/dev/null || true
}

PHP_BIN="$(find_php)"

if [ -z "$PHP_BIN" ] || [ ! -f "$PLUGIN/kook-bot.php" ]; then
    echo "[$(date '+%F %T')] PHP 或 kook-bot.php 不存在" >> "$RUN_DIR/watchdog.log"
    exit 1
fi

is_alive() {
    local pid="$1"
    [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null
}

PID=""
if [ -f "$PID_FILE" ]; then
    PID="$(tr -dc '0-9' < "$PID_FILE")"
fi

if is_alive "$PID"; then
    if [ -f "$HEARTBEAT_FILE" ]; then
        NOW="$(date +%s)"
        BEAT="$(tr -dc '0-9' < "$HEARTBEAT_FILE")"
        AGE=$((NOW - ${BEAT:-0}))

        if [ "$AGE" -le "$MAX_HEARTBEAT_AGE" ]; then
            exit 0
        fi

        echo "[$(date '+%F %T')] 心跳超时 ${AGE}s，重启 PID $PID" >> "$RUN_DIR/watchdog.log"
        kill "$PID" 2>/dev/null || true
        sleep 2
        is_alive "$PID" && kill -9 "$PID" 2>/dev/null || true
    else
        exit 0
    fi
fi

# A process may be running without a usable PID file yet.
if pgrep -f '[M]oePanel/kook-bot.php' >/dev/null 2>&1; then
    exit 0
fi

rm -f "$PID_FILE"

cd "$ROOT" || exit 1
nohup "$PHP_BIN" "$PLUGIN/kook-bot.php" >> "$LOG_FILE" 2>&1 &
NEW_PID=$!
echo "[$(date '+%F %T')] 启动 Bot，shell PID $NEW_PID" >> "$RUN_DIR/watchdog.log"
exit 0
