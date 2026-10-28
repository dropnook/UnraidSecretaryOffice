<h1>
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/title-dark.svg">
    <img src=".github/title-light.svg" alt="Unraid Secretary Office" height="44">
  </picture>
</h1>

A whole team helps you run your Unraid server, right in Unraid's web UI: backups that come back, snapshots, a media
cache for Emby, Jellyfin and Plex, a tidy pool, a night watchman and every log in one place.

[Product page](https://uso.dropnook.app) · [Full guide](docs/GUIDE.md)

<a name="team"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/team-dark.webp"><img src="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/team-light.webp" alt="The team: the Team Lead, Mr. Backupsy, Mr. Restori, Ms. Snapshotini, Jack Streamer, Ms. Moverelli, the Night Watchman, Ms. Dustdevil, Ms. Protocolli and the Consultant"></picture></a>

Start with the Team Lead. He looks at your server and suggests whom to hire.

## Backups that come back

- **Mr. Backupsy** backs up on your schedule: snapshots, database dumps and, encrypted with Kopia, offsite.
- **Mr. Restori** brings back apps, VMs, whole shares or single files, hands you the flash archive — and rehearses it
  on throwaway copies.
- **A partner:** with a second Unraid server running the office, you keep each other's backups — over your own network
  or the office's own Tailscale node.

<a name="backup-run"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/backup-dark.png"><img src="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/backup-light.png" alt="A backup running"></picture></a>

## A fast, tidy pool

Jack Streamer puts what you watch next in Emby, Jellyfin or Plex on the fast pool, so the array disks can sleep.
Ms. Moverelli, with helmi1987's Smart Mover, takes the oldest files to the array only when the pool is above your limit.
Both work in one joint run on your schedule, live to watch.

<a name="moverelli-live"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/moverelli-live-dark.png"><img src="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/moverelli-live-light.png" alt="The pool before and after the joint run"></picture></a>

## The Night Watchman

A round every five minutes; he only reads. What is unusual goes into his watch book: a sign-in from a new address, a
new plugin, a new device on the LAN — and your router's log, if you like.

<a name="watchman-book"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/watchman-dark.png"><img src="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/watchman-light.png" alt="The watch book"></picture></a>

Also on the team: Ms. Snapshotini, Ms. Dustdevil, Ms. Protocolli and the Consultant. Five languages, Unraid's themes,
search with ⌘K, on the phone too. **[The full guide](docs/GUIDE.md)** walks through every desk.

## Careful by design

- **Behind Unraid's login.** Other web pages in your browser can't trigger anything.
- **Asks first.** It shows what will happen before anything changes. What it replaces or tidies up is set aside, never
  deleted at once.
- **Your copies go where you send them:** to your partner, or encrypted to a storage of your choice.
- **Partners stay independent.** A partner accepts copies, nothing more — taken over, say by ransomware, it can't delete
  or overwrite anything on yours.
- **Only what the desks define.** The service runs as root, but it offers only the actions written in the desks' code.
- **Shows what is really there.** Protection comes from what the backups made, not from the settings; what couldn't be
  checked says so.
- **Checked:** what was looked at and what it found, in [HARDENING.md](HARDENING.md).

## Install

Unraid **7.3.2 or a newer 7.x**; snapshots need ZFS or btrfs, Jack Streamer needs Python 3 (e.g. *Python 3 for UNRAID*
from *Apps*). In Unraid, go to *Plugins → Install Plugin* and paste:

```
https://github.com/dropnook/UnraidSecretaryOffice/releases/latest/download/unraid-secretary-office.plg
```

Then open **Office** in Unraid's menu bar (*Sekretariat*, *Segreteria* … when Unraid speaks another language); the Team
Lead welcomes you. Coming soon to Community Applications; updates come through Unraid's plugin manager either way.

## Support

Questions, problems and wishes: in the [Unraid forum thread](https://forums.unraid.net/topic/201085-plugin-unraid-secretary-office-uso-backups-restore-snapshots-media-cache-for-embyjellyfinplex-smart-mover-security-watch-and-more/), right in the
office, no GitHub account needed (*⋯ → Report a problem or a wish…* — you see every part before it is sent), or
[GitHub issues](https://github.com/dropnook/UnraidSecretaryOffice/issues). A [tip](https://tip.uso.dropnook.app/) is
welcome.

## Special thanks

Jack Streamer's tools [MediaCache](https://github.com/helmi1987/mediacache-for-unraid) and
[media-disk-gather](https://github.com/helmi1987/media-disk-gather-for-unraid) (folder consolidation) and Ms. Moverelli's
[Smart Mover](https://github.com/helmi1987/custom-mover-for-unraid) are the work of
**[helmi1987](https://github.com/helmi1987)**, GPL-3.0-or-later; they ship in `mediacache/`, `gather/` and `smartmover/`,
with our changes listed in their READMEs. Thank you — a share of the tips goes to him.

The syslog rules of Ms. Protocolli come from [Enhanced Log](https://github.com/dkaser/unraid-enhancedlog) by
**dlandon** and **Derek Kaser (EDACerton)**, GPL-2.0-or-later (used here under GPL-3.0-or-later); they ship unchanged
in `agent/desks/logs-rules/`. Thank you.

## License

Copyright (c) 2026 Benjamin Müller

License: GPL-3.0-or-later. This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later
version. It is distributed WITHOUT ANY WARRANTY; see [LICENSE](LICENSE).

**No warranty for your backups either.** A backup can be faulty or incomplete. You stay responsible for your data:
test a restore now and then (Mr. Restori's drill helps), and keep more than one copy, for example 3-2-1.

Unraid is a trademark of Lime Technology, Inc. Unraid Secretary Office is an independent project and not affiliated
with it.
