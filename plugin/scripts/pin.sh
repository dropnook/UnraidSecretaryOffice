#!/bin/bash
# Unraid Secretary Office - the office's PIN, from Unraid's terminal (root only).
#
#   pin.sh status    is a PIN set, does looking need it too, who waits how long after wrong tries
#   pin.sh unblock   lifts the waiting times only: the PIN and its secret stay, so browsers
#                    that are unlocked stay unlocked
#   pin.sh reset     forgets the PIN (like deleting data/office/auth.json): anyone who may open
#                    the office can change things again until a new PIN is set
#
# The work is done by src/auth.php (officeAuthCli()) with Unraid's PHP: the same code, lock
# (data/office/.auth.lock) and file handling as the page; auth.json stays 0600 with its owner.
# The data folder: DATA_DIR from the plugin's .cfg (src/place.php, like the page and the agent);
# from a clone (the Compose stack) its data/ folder; OFFICE_DATA_DIR wins over both.
# Started through bash, never run directly.

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd) || exit 1
if [[ -f "$HERE/../src/auth.php" ]]; then
    CODE=${HERE%/*}                                      # the plugin: scripts/ next to src/
elif [[ -f "$HERE/../../src/auth.php" ]]; then
    CODE=${HERE%/*/*}                                    # a clone: plugin/scripts/
    export OFFICE_DATA_DIR="${OFFICE_DATA_DIR:-$CODE/data}"
else
    echo "The office's code isn't next to $HERE." >&2
    exit 1
fi

case "$1" in
    status|unblock|reset) ;;
    *) echo "Usage: bash $0 status|unblock|reset"; exit 2 ;;
esac
if [[ $EUID -ne 0 ]]; then
    echo "Only root can do this - in Unraid's terminal." >&2
    exit 1
fi

cd / || exit 1
exec php -r 'require $argv[1]; exit(officeAuthCli($argv[2]));' -- "$CODE/src/bootstrap.php" "$1"
