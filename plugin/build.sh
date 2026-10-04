#!/bin/bash
# Builds the Unraid plugin: dist/unraid-secretary-office-<version>.txz and
# dist/unraid-secretary-office.plg (with that version and the package's SHA256).
#
#   bash plugin/build.sh <office-version> [plugin-version] [changes-file]
#
#   office-version   the release, e.g. 1.14.0 (tag v1.14.0) - must match
#                    OFFICE_VERSION and AGENT_VERSION in the code
#   plugin-version   what Unraid compares (strcmp!): YYYY.MM.DD, a letter
#                    added for a second one that day (default: today, UTC)
#   changes-file     text for <CHANGES> (default: "Office <version>")
#
# The GitHub Action (.github/workflows/plugin.yml) runs this when a release
# is published and attaches both files to it. The package holds the web files
# (public/) at the top of the plugin folder with src/, agent/ and backup/ next
# to them, plus plugin/scripts, plugin/event and the menu entry.

set -euo pipefail
cd "$(dirname "$0")/.."

name=unraid-secretary-office
office=${1:?office version, e.g. 1.14.0}
office=${office#v}
version=${2:-$(date -u +%Y.%m.%d)}
changes_file=${3:-}
support=${SUPPORT_URL:-https://github.com/vipermark2/UnraidSecretaryOffice/issues}

[[ "$version" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]] || { echo "plugin version must look like 2026.10.05 (or 2026.10.05a)"; exit 1; }
for f in src/bootstrap.php:OFFICE_VERSION agent/agent.php:AGENT_VERSION; do
    have=$(sed -n "s/^const ${f#*:} *= *'\([^']*\)';.*/\1/p" "${f%%:*}")
    [[ "$have" == "$office" ]] || { echo "${f#*:} in ${f%%:*} is '$have', not '$office'"; exit 1; }
done

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
pkg="$stage/$name"
mkdir -p "$pkg" dist

cp -R public/. "$pkg/"
cp -R src agent backup "$pkg/"
cp -R plugin/scripts plugin/event plugin/images "$pkg/"
cp plugin/*.page LICENSE README.md "$pkg/"
rm -f "$pkg/robots.txt"                       # Unraid serves its own
find "$pkg" \( -name '.DS_Store' -o -name '._*' -o -name '.smbdelete*' -o -name '.gitkeep' \) -exec rm -rf {} +

find "$pkg" -type d -exec chmod 755 {} +
find "$pkg" -type f -exec chmod 644 {} +
chmod 755 "$pkg"/scripts/* "$pkg"/event/* "$pkg"/backup/*.sh "$pkg"/agent/agent.php

txz="dist/$name-$version.txz"
if tar --version 2>/dev/null | grep -q GNU; then
    tar --owner=0 --group=0 --sort=name -cJf "$txz" -C "$stage" "$name"
else
    tar --uid 0 --gid 0 -cJf "$txz" -C "$stage" "$name"      # bsdtar (macOS)
fi
sha256=$( (sha256sum "$txz" 2>/dev/null || shasum -a 256 "$txz") | cut -d' ' -f1)

if [[ -n "$changes_file" && -s "$changes_file" ]]; then
    changes=$(cat "$changes_file")
else
    changes="Office $office"
fi
# <CHANGES> is XML text: escape it, then hand it to awk (no sed delimiters to trip over)
changes=$(printf '%s\n' "### $office ($version)" "" "$changes" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g')

CHANGES="$changes" awk -v v="$version" -v o="$office" -v s="$sha256" -v u="$support" '
    /@CHANGES@/ { print ENVIRON["CHANGES"]; next }
    { gsub(/@VERSION@/, v); gsub(/@OFFICE_VERSION@/, o); gsub(/@SHA256@/, s); gsub(/@SUPPORT@/, u); print }
' plugin/$name.plg > "dist/$name.plg"

if grep -q '@[A-Z_]*@' "dist/$name.plg"; then
    echo "placeholders left in dist/$name.plg"; exit 1
fi
echo "dist/$name.plg  version $version (office $office)"
echo "$txz  sha256 $sha256"
