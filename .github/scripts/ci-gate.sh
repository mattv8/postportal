#!/usr/bin/env bash
set -euo pipefail

readonly REQUIRED_JOBS=(phpunit phpstan composer-audit readme-consistency container-runtime)

if [[ -z "${NEEDS_JSON:-}" ]] || ! command -v jq >/dev/null || ! jq -e . >/dev/null <<<"$NEEDS_JSON"; then
    echo 'NEEDS_JSON must be a valid JSON object.' >&2
    exit 1
fi

for job in "${REQUIRED_JOBS[@]}"; do
    result="$(jq -r --arg job "$job" '.[$job].result // empty' <<<"$NEEDS_JSON")"
    if [[ "$result" != 'success' ]]; then
        echo "Required job '$job' did not succeed (result: ${result:-missing})." >&2
        exit 1
    fi
done
