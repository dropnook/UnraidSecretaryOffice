#!/usr/bin/env python3
"""Lab only: mark a film watched (played, no resume) for every user on a lab server's Emby, Jellyfin and Plex.
  python3 -I tools/lab/watched.py <tower|test3> "<film title start>" | "*"   (lab.py reset puts the defined state back)"""
import os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import lab

srv, title = sys.argv[1], sys.argv[2]          # title "*": every film and episode (Jack then gives everything back)
env = lab.load_env()
for kind in ("emby", "jellyfin"):
    mb = lab.MB(kind, lab.SERVERS[srv])
    mb.login("labadmin", env[f"{srv.upper()}_{kind.upper()}_PW_LABADMIN"])
    for u in mb.req("GET", "/Users"):
        items = (mb.req("GET", f"/Users/{u['Id']}/Items", Recursive="true", IncludeItemTypes="Movie,Episode")["Items"]
                 if title == "*" else [lab.mb_find(mb, u["Id"], title)])
        for it in items:
            lab.mb_userdata(mb, u["Id"], it["Id"], {"PlaybackPositionTicks": 0, "Played": True, "PlayCount": 1})
            lab.mb_played(mb, u["Id"], it["Id"], True)
px = lab.Plex(lab.SERVERS[srv])
if title == "*":
    keys = [m["ratingKey"] for key in lab.plex_sections(px).values() for typ in (1, 4)
            for m in px.mc(f"/library/sections/{key}/all", type=typ).get("Metadata") or []]
else:
    keys = [lab.plex_find(px, title)["ratingKey"]]
for k in keys:
    px.req("GET", "/:/scrobble", want="text", key=k, identifier="com.plexapp.plugins.library")
print(f"{srv}: «{title}» watched for everyone")
