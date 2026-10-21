#!/usr/bin/env bash
set -euo pipefail

gate_script="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)/.github/scripts/ci-gate.sh"

run_case() {
    local name="$1"
    local needs_json="$2"
    local expected_status="$3"

    if NEEDS_JSON="$needs_json" bash "$gate_script"; then
        actual_status=0
    else
        actual_status=$?
    fi

    if [[ "$actual_status" != "$expected_status" ]]; then
        echo "$name: expected exit $expected_status, got $actual_status" >&2
        exit 1
    fi
}

success='{"phpunit":{"result":"success"},"phpstan":{"result":"success"},"composer-audit":{"result":"success"},"security-checks":{"result":"success"},"readme-consistency":{"result":"success"},"container-runtime":{"result":"success"}}'
run_case 'all mandatory jobs succeed' "$success" 0
run_case 'failed dependency blocks gate' "${success/success/failed}" 1
run_case 'cancelled dependency blocks gate' "${success/success/cancelled}" 1
run_case 'skipped dependency blocks gate' "${success/success/skipped}" 1
run_case 'missing dependency blocks gate' '{"phpunit":{"result":"success"}}' 1
run_case 'malformed payload blocks gate' 'not-json' 1
