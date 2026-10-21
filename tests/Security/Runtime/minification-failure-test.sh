#!/usr/bin/env bash
set -euo pipefail

work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT
mkdir -p "$work_dir/js" "$work_dir/node_modules/.bin"
printf 'good' > "$work_dir/js/good.js"
printf 'bad' > "$work_dir/js/bad.js"
cat > "$work_dir/node_modules/.bin/terser" <<'SH'
#!/usr/bin/env bash
[[ "$1" != *bad.js ]] || exit 1
output="${!#}"
printf 'minified' > "$output"
SH
chmod +x "$work_dir/node_modules/.bin/terser"

if ! (
    cd "$work_dir"
    find js -maxdepth 1 -name '*.js' -type f -exec sh -c \
        'status=0; for file do if ./node_modules/.bin/terser "$file" -c -m --comments false -o "$file.min" && mv "$file.min" "$file"; then :; else status=1; fi; done; exit "$status"' sh {} +
); then
    [[ "$(cat "$work_dir/js/good.js")" == minified ]]
    printf 'minification failure propagation test passed\n'
    exit 0
fi

printf 'minifier child failure unexpectedly succeeded\n' >&2
exit 1
