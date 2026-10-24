#!/usr/bin/env bash
# Every `uses:` in the workflows must reference a full 40-character commit SHA
# (supply-chain pinning). Local actions (./...) are allowed.
set -euo pipefail
dir="${1:-.github/workflows}"
bad=0
while IFS= read -r line; do
  ref="$(sed -E 's/.*uses:[[:space:]]*([^[:space:]#]+).*/\1/' <<< "${line#*:*:}")"
  case "$ref" in
    ./*) continue ;;
  esac
  if ! [[ "$ref" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+@[0-9a-f]{40}$ ]]; then
    echo "::error title=action not pinned::$line"
    bad=1
  fi
done < <(grep -nE '^[[:space:]-]*uses:' "$dir"/*.yml "$dir"/*.yaml 2>/dev/null || true)
[ "$bad" = 0 ] && echo "All actions pinned to commit SHAs: PASS"
exit "$bad"
