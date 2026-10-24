#!/usr/bin/env bash
# Publishes the tail of a log as GitHub error annotations, so failures can be
# read from the checks API (job logs alone are not always reachable).
# Annotation messages are capped at ~4 KB, so the tail is split into parts.
# Usage: annotate_failure.sh <log-file> <title> [lines] [max-parts]
set -uo pipefail
file="$1"; title="$2"; lines="${3:-150}"; parts="${4:-4}"
[ -f "$file" ] || { echo "::error title=$title::log file not found"; exit 0; }
tmp="$(mktemp -d)"
tail -n "$lines" "$file" | sed -e 's/\x1b\[[0-9;]*m//g' > "$tmp/tail"
# Prefer the failure details when the log has them (Pest / test runners).
start="$(grep -n -m1 -E '(FAILED|⨯|FAIL )' "$tmp/tail" | cut -d: -f1)"
[ -n "$start" ] && [ "$start" -gt 3 ] && tail -n +"$((start - 3))" "$tmp/tail" > "$tmp/sel" || cp "$tmp/tail" "$tmp/sel"
split -C 3800 -d -a 2 "$tmp/sel" "$tmp/part."
i=0
for p in "$tmp"/part.*; do
  i=$((i + 1)); [ "$i" -gt "$parts" ] && break
  body="$(sed -e 's/%/%25/g' "$p" | awk 'BEGIN{ORS="%0A"} {print}')"
  echo "::error title=$title ($i)::$body"
done
rm -rf "$tmp"
