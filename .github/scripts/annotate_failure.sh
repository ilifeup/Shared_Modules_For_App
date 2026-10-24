#!/usr/bin/env bash
# Publishes the tail of a log as a GitHub error annotation, so failures can be
# read from the checks API (job logs alone are not always reachable).
# Usage: annotate_failure.sh <log-file> <title> [lines]
set -uo pipefail
file="$1"; title="$2"; lines="${3:-150}"
[ -f "$file" ] || { echo "::error title=$title::log file not found"; exit 0; }
body="$(tail -n "$lines" "$file" | sed -e 's/\x1b\[[0-9;]*m//g' | sed -e 's/%/%25/g' | awk 'BEGIN{ORS="%0A"} {print}')"
echo "::error title=$title::$body"
