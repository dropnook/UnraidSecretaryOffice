<h1>
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/title-dark.svg">
    <img src=".github/title-light.svg" alt="Unraid Secretary Office" height="44">
  </picture>
</h1>

A whole team supports you in running your Unraid server, right in Unraid's web UI: backups and restore, snapshots, a media
cache for Emby, Jellyfin and Plex, the mover, a night watchman and every log in one place. More on the [product page](https://uso.dropnook.app).

<a name="team"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/team-dark.webp"><img src="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/team-light.webp" alt="The team of the Unraid Secretary Office: the Team Lead, Mr. Backupsy, Mr. Restori, Ms. Snapshotini, Jack Streamer, Ms. Moverelli, the Night Watchman, Ms. Dustdevil, Ms. Protocolli and the Consultant — each with one sentence about their work"></picture></a>

A fresh office has only the Team Lead. He looks at your server and suggests whom to hire.

## Backups that come back

Mr. Backupsy backs up on your schedule: snapshots, database dumps and, encrypted with Kopia, offsite. Mr. Restori
brings back apps, VMs, whole shares or single files from every place a copy is kept, hands you the flash archive — and
rehearses it regularly on throwaway copies. A second Unraid server with the office becomes a partner: the two keep each
other's backups, over your own network or the office's own Tailscale node.

<a name="backup-run"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/backup-dark.png"><img src="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/backup-light.png" alt="Mr. Backupsy: a run going on — its steps, its offsite sources, how long still"></picture></a>

## The pool: fast and tidy

Jack Streamer puts on the fast pool what you watch next in Emby, Jellyfin or Plex — the array disks may sleep. Ms. Moverelli adds
helmi1987's Smart Mover to Unraid's mover: she moves to the array what hasn't been used on the pool for a while, and
leaves what is current in the cache. Both in one joint run on a schedule, live to watch.

<a name="moverelli-live"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/moverelli-live-dark.png"><img src="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/moverelli-live-light.png" alt="Ms. Moverelli's joint run, live: the pool before and after, what Jack Streamer laid out, the array beside it"></picture></a>

## The Night Watchman

A round every five minutes. He only reads and changes nothing. Only what is different from usual goes into his watch
book: a sign-in from a new address, a new plugin, a new device on the LAN — and, if you like, from your router's log
too.

<a name="watchman-book"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/watchman-dark.png"><img src="https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/watchman-light.png" alt="The Night Watchman&#x27;s watch book: what was different from normal"></picture></a>

Plus Ms. Snapshotini, Ms. Dustdevil, Ms. Protocolli and the Consultant; five languages, Unraid's themes, search with
⌘K, on the phone too. **[The full guide](docs/GUIDE.md)** walks through every desk.

## Careful by design

- **Behind Unraid's login.** Only whoever is logged in to Unraid gets in; other web pages in your browser can't
  trigger anything.
- **Looks before it touches.** Before anything changes, it shows you what will happen and asks. What is replaced or
  tidied up is set aside — nothing is deleted at once.
- **You're in control.** Copies leave the server only where you send them: to your partner server, or encrypted to a
  storage of your choice.
- **Partners stay independent.** A partner server accepts copies, nothing more. If one is taken over, say by
  ransomware, it can delete or overwrite nothing on the other.
- **Only what the desks define.** The service on the server can do what root can, but it only offers the actions
  written in the desks' code.
- **Says what is really there.** Protection is shown from what the runs made, not from the settings; what couldn't be
  checked says so.
- **Checked.** What was looked at, and what it found: [HARDENING.md](HARDENING.md).

## Install

Unraid **7.3.2 or a newer 7.x**; snapshots need ZFS or btrfs. In Unraid, go to *Plugins → Install Plugin* and paste:

```
https://github.com/dropnook/UnraidSecretaryOffice/releases/latest/download/unraid-secretary-office.plg
```

Then open **Sekretariat** in Unraid's menu bar; the Team Lead welcomes you. Coming soon to Community Applications;
updates come through Unraid's plugin manager either way.

## Support

A [tip](https://tip.uso.dropnook.app/) is welcome. Problems and wishes: right in
the office, no GitHub account needed (*⋯ → Report a problem or a wish…*, you see every part before it is sent), or
[GitHub issues](https://github.com/dropnook/UnraidSecretaryOffice/issues).

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
