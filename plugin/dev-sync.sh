#!/bin/bash
# Development on a server that runs the plugin: copies this working copy into
# the installed plugin (RAM), in the package's layout.
#
#   bash plugin/dev-sync.sh          (on the Unraid host, from any folder)
#
# The page reads its files on every request; the agent notices its changed
# files within seconds, lints them and restarts itself ("Agent code changed"
# in data/agent.log). The copy lasts until the next reboot or plugin update -
# then the installed package counts again. Nothing in the data folder is touched.
# While a backup run is active, backup/ is left alone (the run uses it).

set -euo pipefail
src=$(cd "$(dirname "$0")/.." && pwd -P)
dir=/usr/local/emhttp/plugins/unraid-secretary-office

[[ -d "$dir/agent" ]] || { echo "The plugin is not installed ($dir)."; exit 1; }
[[ -f "$src/agent/agent.php" && -d "$src/public" ]] || { echo "$src is not a working copy of the office."; exit 1; }

# a new file + rename for every changed file (rsync's default): a running
# bash or PHP keeps reading the old one
copy() { rsync -rlt --delete --chmod=D755,F644 "$@"; }

copy "$src/public/assets/" "$dir/assets/"
copy "$src/public/desks/"  "$dir/desks/"
copy "$src/public/lang/"   "$dir/lang/"
rsync -lt --chmod=F644 "$src/public/index.php" "$src/public/api.php" "$dir/"
copy "$src/src/"           "$dir/src/"
copy "$src/agent/"         "$dir/agent/"
copy "$src/plugin/scripts/" "$dir/scripts/"
copy "$src/plugin/event/"  "$dir/event/"
copy "$src/plugin/images/" "$dir/images/"
copy --exclude=__pycache__ "$src/embycache/" "$dir/embycache/"     # Jack Emby's tools; a running one keeps its old files
copy "$src/gather/"        "$dir/gather/"
rsync -lt --chmod=F644 "$src"/plugin/*.page "$dir/"
for page in "$dir"/*.page; do        # pages the working copy doesn't have any more (renamed) go
    [[ -e "$src/plugin/$(basename "$page")" ]] || rm -f "$page"
done
# the office's entry (label, place) as chosen in its ⋯ menu (as the .plg does at every boot)
php -r 'require $argv[1] . "/src/place.php"; officeMenuPageApply($argv[1], officeMenuName(), officeMenuPlace());' "$dir"
# the short README Unraid shows in its list under Plugins (as plugin/build.sh does)
rsync -lt --chmod=F644 "$src/plugin/README.md" "$dir/README.md"
chmod 755 "$dir"/scripts/* "$dir"/event/* "$dir/agent/agent.php"

# a run is "bash <dir>/backup/backup.sh" (atd, the cron file) - only the start of the command line counts
if pgrep -f "^(/usr)?(/bin/)?bash $dir/backup/(backup|setup)\.sh" >/dev/null 2>&1; then
    echo "A backup run is active - backup/ was left as it is."
else
    copy "$src/backup/" "$dir/backup/"
    chmod 755 "$dir"/backup/*.sh
fi
echo "Copied $src into $dir."
