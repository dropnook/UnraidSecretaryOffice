#!/bin/bash
# Unraid Secretary Office - the partner door: the forced command of a pair's line in
# /boot/config/ssh/root/authorized_keys (restrict,from="<partner>",command="<this> <pair id>").
# Whatever the partner's ssh asks for, this runs; agent/partner-door.php reads the request
# (SSH_ORIGINAL_COMMAND) word by word and answers only the door's few verbs.
# cwd /, umask 077, an empty environment but PATH and the two SSH_ variables the door reads.
cd / || exit 2
umask 077
exec /usr/bin/env -i PATH=/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin LC_ALL=C HOME=/root \
    SSH_ORIGINAL_COMMAND="${SSH_ORIGINAL_COMMAND:-}" SSH_CONNECTION="${SSH_CONNECTION:-}" \
    /usr/bin/php /usr/local/emhttp/plugins/unraid-secretary-office/agent/partner-door.php "$1"
