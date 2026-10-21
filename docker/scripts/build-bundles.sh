#!/bin/bash
# Build JavaScript bundles for production
# This script concatenates related JS files into bundles and minifies them with terser

set -e

# Determine JS directory - support both local dev and Docker contexts
if [[ -d "/var/www/html/js" ]]; then
    JS_DIR="/var/www/html/js"
elif [[ -n "$1" ]]; then
    JS_DIR="$1"
else
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    JS_DIR="${SCRIPT_DIR}/../../src/js"
fi

BUNDLE_DIR="${JS_DIR}/bundles"

# Create bundle directory
mkdir -p "$BUNDLE_DIR"

echo "Building JavaScript bundles..."

# Bundle definitions (must match AssetHelper.php)
declare -A BUNDLES

BUNDLES["core.bundle.js"]="shared-utils.js validation-utils.js notifications.js settings-manager.js"
BUNDLES["editor.bundle.js"]="quill-upload-adapter.js auto-save.js ai-title-generator.js post-draft-handler.js publish-confirmation.js unpublish-confirmation.js"
BUNDLES["cropping.bundle.js"]="image-crop-manager.js admin-crop-init.js bg-preview-manager.js"
BUNDLES["home.bundle.js"]="newsletter-signup.js edit-sections.js edit-hero-modal.js home.js"
BUNDLES["admin.bundle.js"]="branding.js newsletter-admin.js user-management.js admin.js"
BUNDLES["auth.bundle.js"]="auth.js"
BUNDLES["post-modal.bundle.js"]="post-modal.js"

# Track bundle info for manifest
declare -A BUNDLE_SIZES

# Production console override - disable console methods to prevent variable dumping
CONSOLE_OVERRIDE='(function(){var n=function(){};if(typeof console==="undefined"){window.console={};}var methods=["log","debug","info","warn","table","dir","dirxml","trace","group","groupCollapsed","groupEnd","time","timeEnd","timeLog","profile","profileEnd","count","countReset","assert","clear"];for(var i=0;i<methods.length;i++){console[methods[i]]=n;}})();'

for bundle_name in "${!BUNDLES[@]}"; do
    files="${BUNDLES[$bundle_name]}"
    bundle_path="${BUNDLE_DIR}/${bundle_name}"
    temp_path="${bundle_path}.tmp"

    echo "  Creating ${bundle_name}..."

    # Concatenate files with semicolon separators for safety
    : > "$temp_path"

    # Add console override to core bundle (loaded first on all pages)
    if [[ "$bundle_name" == "core.bundle.js" ]]; then
        echo "/* Production console override */" >> "$temp_path"
        echo "$CONSOLE_OVERRIDE" >> "$temp_path"
    fi

    for file in $files; do
        if [[ -f "${JS_DIR}/${file}" ]]; then
            {
                echo "/* === ${file} === */"
                cat "${JS_DIR}/${file}"
                echo ";"
            } >> "$temp_path"
        else
            echo "    Warning: ${file} not found, skipping"
        fi
    done

    # Minify with terser
    command -v terser >/dev/null 2>&1 || { echo 'terser is required for production bundles' >&2; exit 1; }
    terser "$temp_path" -c -m --comments false -o "$bundle_path"
    rm "$temp_path"

    # Track size
    if [[ -f "$bundle_path" ]]; then
        size=$(wc -c < "$bundle_path")
        BUNDLE_SIZES["$bundle_name"]=$size
        echo "    Size: ${size} bytes"
    fi
done

# Create manifest with version and bundle info
BUILD_TIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
VERSION=$(date +%s)

cat > "${BUNDLE_DIR}/manifest.json" << EOF
{
    "version": "${VERSION}",
    "buildTime": "${BUILD_TIME}",
    "bundles": {
EOF

first=true
for bundle_name in "${!BUNDLE_SIZES[@]}"; do
    if [ "$first" = true ]; then
        first=false
    else
        echo "," >> "${BUNDLE_DIR}/manifest.json"
    fi
    printf '        "%s": {"size": %s, "files": [%s]}' \
        "$bundle_name" \
        "${BUNDLE_SIZES[$bundle_name]}" \
        "$(echo "${BUNDLES[$bundle_name]}" | sed 's/\([^ ]*\)/"\1"/g' | tr ' ' ',')" \
        >> "${BUNDLE_DIR}/manifest.json"
done

cat >> "${BUNDLE_DIR}/manifest.json" << EOF

    }
}
EOF

echo ""
echo "Bundle build complete!"
echo "Manifest: ${BUNDLE_DIR}/manifest.json"

# Summary
total_size=0
for size in "${BUNDLE_SIZES[@]}"; do
    total_size=$((total_size + size))
done
total_kilobytes=$(awk -v bytes="$total_size" 'BEGIN { printf "%.2f", bytes / 1024 }')
echo "Total bundle size: ${total_size} bytes (${total_kilobytes}KB)"
