#!/usr/bin/env python3
"""Media-server lab for Jack Streamer / MediaCache (task/mediacache-lab, never in main).

Runs on the Mac against the throw-away test servers. Secrets live only in
~/Claude/UnraidSecretaryOffice-briefs/mediacache/lab-secrets.env (created/extended here).

  python3 -I tools/lab/lab.py setup  [tower|test3]   wizard, users, API keys, libraries, scan (idempotent)
  python3 -I tools/lab/lab.py reset  [tower|test3]   watch state back to the lab's defined state
  python3 -I tools/lab/lab.py check  [tower|test3]   continue watching / next up / on deck per user

Plex: unclaimed, ONE local user; reached without a token from ALLOWED_NETWORKS (LAN). Never plex.tv.
"""
import json, os, secrets, sys, time, urllib.error, urllib.parse, urllib.request

SECRETS = os.path.expanduser("~/Claude/UnraidSecretaryOffice-briefs/mediacache/lab-secrets.env")
SERVERS = {"tower": "192.168.7.111", "test3": "192.168.7.197"}
EMBY_PORT, JELLY_PORT, PLEX_PORT = 8096, 8097, 32400
USERS = ["labadmin", "mia", "leo"]          # labadmin = the admin made by the wizard
LIBS = {  # name -> (collection type, folder below the share)
    "Familienfilme": ("movies", "Familienfilme"),
    "Familienserien": ("tvshows", "Familienserien"),
}
ROOT = {"emby": "/mnt/media", "jellyfin": "/data/media", "plex": "/media"}   # container side of /mnt/user/media
EXPECT = {"Movie": 8, "Series": 3, "Episode": 23}

# The defined watch state. film = title, ep = (series, season, episode); resume = fraction of the runtime
STATE = {
    "labadmin": {"played": ["Omas 80. Geburtstag", ("Gartenprojekt", 1, 1), ("Gartenprojekt", 1, 2), ("Gartenprojekt", 1, 3)],
                 "resume": [("Ferien am See", 0.40)]},
    "mia": {"played": ["Hochzeit im Garten", ("Unser Hausbau", 1, 1), ("Unser Hausbau", 1, 2)],
            "resume": [("Skiurlaub in den Bergen", 0.25), (("Unser Hausbau", 1, 3), 0.50)]},
    "leo": {"played": [("Kinderzimmer-Konzerte", 1, e) for e in range(1, 5)],
            "resume": [("Weihnachten zu Hause", 0.60)]},
}
PLEX_STATE = {"played": STATE["labadmin"]["played"] + STATE["leo"]["played"],
              "resume": [("Ferien am See", 0.40), ("Weihnachten zu Hause", 0.60)]}


# ------------------------------------------------------------------ secrets
def load_env():
    env = {}
    if os.path.exists(SECRETS):
        for line in open(SECRETS):
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                env[k] = v.strip("'\"")
    return env


def save_env(env):
    os.makedirs(os.path.dirname(SECRETS), exist_ok=True)
    tmp = SECRETS + ".tmp"
    with open(tmp, "w") as f:
        f.write("# Media-server lab secrets (Tower, USOTest3VM) - never into git, never into a report\n")
        for k in sorted(env):
            f.write(f"{k}='{env[k]}'\n")
    os.chmod(tmp, 0o600)
    os.replace(tmp, SECRETS)


# ------------------------------------------------------------------ http
def http(method, url, body=None, headers=None, want="json", timeout=30):
    data = None
    hdr = dict(headers or {})
    if body is not None:
        data = json.dumps(body).encode()
        hdr["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, headers=hdr, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            raw = r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        raise RuntimeError(f"{method} {urllib.parse.urlsplit(url).path} -> HTTP {e.code} {e.read()[:200]!r}") from None
    if want == "json":
        return json.loads(raw) if raw.strip() else None
    return raw


class MB:
    """Emby and Jellyfin (same family of API)."""

    def __init__(self, kind, host):
        self.kind, self.host = kind, host
        port = EMBY_PORT if kind == "emby" else JELLY_PORT
        self.base = f"http://{host}:{port}" + ("/emby" if kind == "emby" else "")
        self.token = None

    def auth_header(self):
        h = 'MediaBrowser Client="uso-lab", Device="mac", DeviceId="uso-lab-mac", Version="1.0"'
        if self.token:
            h += f', Token="{self.token}"'
        return {"X-Emby-Authorization": h, "Authorization": h}

    def req(self, method, path, body=None, **params):
        q = ("?" + urllib.parse.urlencode(params)) if params else ""
        return http(method, self.base + path + q, body, self.auth_header())

    def public(self):
        return self.req("GET", "/System/Info/Public")

    def wizard_done(self):
        if self.kind == "jellyfin":
            return bool(self.public().get("StartupWizardCompleted"))
        try:  # Emby: the startup endpoints answer only while the wizard is open
            self.req("GET", "/Startup/Configuration")
            return False
        except RuntimeError:
            return True

    def login(self, user, pw):
        r = self.req("POST", "/Users/AuthenticateByName", {"Username": user, "Pw": pw, "Password": pw})
        self.token = r["AccessToken"]
        return r["User"]["Id"]


# ------------------------------------------------------------------ setup
def setup_mb(srv, kind, env):
    host = SERVERS[srv]
    p = f"{srv.upper()}_{kind.upper()}_"
    mb = MB(kind, host)
    env[p + "URL"] = mb.base.replace("/emby", "")
    for u in USERS:
        env.setdefault(p + f"PW_{u.upper()}", secrets.token_urlsafe(12))
    admin_pw = env[p + "PW_LABADMIN"]
    if not mb.wizard_done():
        mb.req("POST", "/Startup/Configuration", {"UICulture": "de-DE", "MetadataCountryCode": "CH",
                                                  "PreferredMetadataLanguage": "de"})
        mb.req("GET", "/Startup/User")
        mb.req("POST", "/Startup/User", {"Name": "labadmin", "Password": admin_pw})
        try:
            mb.req("POST", "/Startup/RemoteAccess", {"EnableRemoteAccess": True, "EnableAutomaticPortMapping": False})
        except RuntimeError:
            pass
        mb.req("POST", "/Startup/Complete")
        print(f"{srv} {kind}: wizard done")
    mb.login("labadmin", admin_pw)

    # server name, short items resumable (lab clips are 10-30 s; default minimum is 300 s)
    cfg = mb.req("GET", "/System/Configuration")
    want = {"ServerName": f"{srv}-{kind}", "MinResumeDurationSeconds": 5, "MinResumePct": 1, "MaxResumePct": 95}
    if any(cfg.get(k) != v for k, v in want.items()):
        cfg.update(want)
        mb.req("POST", "/System/Configuration", cfg)

    # users
    have = {u["Name"]: u["Id"] for u in mb.req("GET", "/Users")}
    for u in USERS[1:]:
        if u not in have:
            nu = mb.req("POST", "/Users/New", {"Name": u, "Password": env[p + f"PW_{u.upper()}"]})
            uid = nu["Id"]
            if kind == "emby":  # Emby ignores the password on create
                mb.req("POST", f"/Users/{uid}/Password", {"Id": uid, "CurrentPw": "", "NewPw": env[p + f"PW_{u.upper()}"]})
            print(f"{srv} {kind}: user {u}")

    # API key for MediaCache
    keys = mb.req("GET", "/Auth/Keys")
    items = keys.get("Items", []) if isinstance(keys, dict) else keys
    mine = [k for k in items if (k.get("AppName") or k.get("App") or "").startswith("MediaCache")]
    if not mine:
        mb.req("POST", "/Auth/Keys", **({"App": "MediaCache"} if kind == "emby" else {"app": "MediaCache"}))
        keys = mb.req("GET", "/Auth/Keys")
        items = keys.get("Items", []) if isinstance(keys, dict) else keys
        mine = [k for k in items if (k.get("AppName") or k.get("App") or "").startswith("MediaCache")]
    env[p + "API_KEY"] = mine[0]["AccessToken"]

    # libraries
    have = {f["Name"] for f in mb.req("GET", "/Library/VirtualFolders")}
    for name, (ctype, sub) in LIBS.items():
        if name in have:
            continue
        path = f"{ROOT[kind]}/{sub}"
        body = {"LibraryOptions": {"PathInfos": [{"Path": path}], "EnableRealtimeMonitor": True,
                                   "EnableInternetProviders": False, "MetadataSavers": [], "SaveLocalMetadata": False}}
        mb.req("POST", "/Library/VirtualFolders", body, name=name, collectionType=ctype, refreshLibrary="true",
               paths=path)
        print(f"{srv} {kind}: library {name} -> {path}")
    wait_scan(lambda: mb_counts(mb), f"{srv} {kind}", lambda: mb.req("POST", "/Library/Refresh"))


def mb_counts(mb):
    out = {}
    for t in EXPECT:
        r = mb.req("GET", "/Items", Recursive="true", IncludeItemTypes=t, Limit=0)
        out[t] = r.get("TotalRecordCount", 0)
    return out


def wait_scan(counts, label, kick):
    t0, kicked = time.time(), False
    while True:
        c = counts()
        if all(c.get(k, 0) >= v for k, v in EXPECT.items()):
            print(f"{label}: scanned {c}")
            return
        if time.time() - t0 > 60 and not kicked:
            kick()
            kicked = True
        if time.time() - t0 > 600:
            raise RuntimeError(f"{label}: scan incomplete {c}")
        time.sleep(5)


class Plex:
    def __init__(self, host):
        self.base = f"http://{host}:{PLEX_PORT}"

    def req(self, method, path, want="json", **params):
        q = ("?" + urllib.parse.urlencode(params)) if params else ""
        return http(method, self.base + path + q, None,
                    {"Accept": "application/json", "X-Plex-Client-Identifier": "uso-lab-mac", "X-Plex-Product": "uso-lab"},
                    want)

    def mc(self, path, **params):
        return (self.req("GET", path, **params) or {}).get("MediaContainer") or {}


def setup_plex(srv, env):
    px = Plex(SERVERS[srv])
    p = f"{srv.upper()}_PLEX_"
    env[p + "URL"] = px.base
    env.setdefault(p + "TOKEN", "lab-unclaimed-no-token")   # any non-empty value; LAN needs no token (ALLOWED_NETWORKS)
    have = {d["title"]: d for d in px.mc("/library/sections").get("Directory") or []}
    for name, (ctype, sub) in LIBS.items():
        if name in have:
            continue
        typ, agent, scanner = (("movie", "tv.plex.agents.none", "Plex Movie") if ctype == "movies"
                               else ("show", "tv.plex.agents.none", "Plex TV Series"))
        px.req("POST", "/library/sections", want="text", name=name, type=typ, agent=agent, scanner=scanner,
               language="xn", location=f"{ROOT['plex']}/{sub}")
        print(f"{srv} plex: library {name}")
    wait_scan(lambda: plex_counts(px), f"{srv} plex", lambda: plex_refresh(px))


def plex_sections(px):
    return {d["title"]: d["key"] for d in px.mc("/library/sections").get("Directory") or []}


def plex_refresh(px):
    for key in plex_sections(px).values():
        px.req("GET", f"/library/sections/{key}/refresh", want="text")


def plex_counts(px):
    s = plex_sections(px)
    out = {"Movie": 0, "Series": 0, "Episode": 0}
    if "Familienfilme" in s:
        out["Movie"] = px.mc(f"/library/sections/{s['Familienfilme']}/all", type=1).get("size", 0)
    if "Familienserien" in s:
        out["Series"] = px.mc(f"/library/sections/{s['Familienserien']}/all", type=2).get("size", 0)
        out["Episode"] = px.mc(f"/library/sections/{s['Familienserien']}/all", type=4).get("size", 0)
    return out


# ------------------------------------------------------------------ watch state
def mb_find(mb, uid, what):
    if isinstance(what, str):
        r = mb.req("GET", f"/Users/{uid}/Items", Recursive="true", IncludeItemTypes="Movie", Fields="Path")
        for it in r["Items"]:
            if it["Name"].startswith(what) or what in it.get("Path", ""):
                return it
    else:
        series, s, e = what
        r = mb.req("GET", f"/Users/{uid}/Items", Recursive="true", IncludeItemTypes="Episode", Fields="Path")
        for it in r["Items"]:
            if series in it.get("SeriesName", "") + it.get("Path", "") and it.get("ParentIndexNumber") == s and it.get("IndexNumber") == e:
                return it
    raise RuntimeError(f"not found: {what}")


def mb_userdata(mb, uid, iid, body):
    if mb.kind == "emby":
        return mb.req("POST", f"/Users/{uid}/Items/{iid}/UserData", body)
    return mb.req("POST", f"/UserItems/{iid}/UserData", body, userId=uid)


def mb_played(mb, uid, iid, on):
    if mb.kind == "emby":
        return mb.req("POST" if on else "DELETE", f"/Users/{uid}/PlayedItems/{iid}")
    return mb.req("POST" if on else "DELETE", f"/UserPlayedItems/{iid}", userId=uid)


def reset_mb(srv, kind, env):
    p = f"{srv.upper()}_{kind.upper()}_"
    mb = MB(kind, SERVERS[srv])
    mb.login("labadmin", env[p + "PW_LABADMIN"])
    users = {u["Name"]: u["Id"] for u in mb.req("GET", "/Users")}
    for name in USERS:
        uid = users[name]
        # clear: everything unplayed, no position
        r = mb.req("GET", f"/Users/{uid}/Items", Recursive="true", IncludeItemTypes="Movie,Episode", Fields="UserData")
        for it in r["Items"]:
            ud = it.get("UserData") or {}
            if ud.get("Played"):
                mb_played(mb, uid, it["Id"], False)
            if ud.get("PlaybackPositionTicks"):
                mb_userdata(mb, uid, it["Id"], {"PlaybackPositionTicks": 0, "Played": False})
        now = time.time()
        for i, what in enumerate(STATE[name]["played"]):
            it = mb_find(mb, uid, what)
            mb_played(mb, uid, it["Id"], True)
            when = time.strftime("%Y-%m-%dT%H:%M:%S.0000000Z", time.gmtime(now - 3600 * (len(STATE[name]["played"]) - i)))
            mb_userdata(mb, uid, it["Id"], {"Played": True, "PlayCount": 1, "LastPlayedDate": when})
        for what, frac in STATE[name]["resume"]:
            it = mb_find(mb, uid, what)
            full = mb.req("GET", f"/Users/{uid}/Items/{it['Id']}")
            ticks = int(full.get("RunTimeTicks") or 0)
            when = time.strftime("%Y-%m-%dT%H:%M:%S.0000000Z", time.gmtime(now))
            mb_userdata(mb, uid, it["Id"], {"PlaybackPositionTicks": int(ticks * frac), "Played": False,
                                            "LastPlayedDate": when})
    print(f"{srv} {kind}: watch state set")


def plex_find(px, what):
    s = plex_sections(px)
    if isinstance(what, str):
        for m in px.mc(f"/library/sections/{s['Familienfilme']}/all", type=1).get("Metadata") or []:
            if m["title"].startswith(what):
                return m
    else:
        series, se, ep = what
        for m in px.mc(f"/library/sections/{s['Familienserien']}/all", type=4).get("Metadata") or []:
            if f"/{series} (" in m["Media"][0]["Part"][0]["file"] and m.get("parentIndex") == se and m.get("index") == ep:
                return m
    raise RuntimeError(f"plex not found: {what}")


def reset_plex(srv, env):
    px = Plex(SERVERS[srv])
    s = plex_sections(px)
    ident = "com.plexapp.plugins.library"
    for key in s.values():
        for typ in (1, 4):
            for m in px.mc(f"/library/sections/{key}/all", type=typ).get("Metadata") or []:
                if m.get("viewCount") or m.get("viewOffset"):
                    px.req("GET", "/:/unscrobble", want="text", key=m["ratingKey"], identifier=ident)
    for what in PLEX_STATE["played"]:
        px.req("GET", "/:/scrobble", want="text", key=plex_find(px, what)["ratingKey"], identifier=ident)
    for what, frac in PLEX_STATE["resume"]:
        m = plex_find(px, what)
        dur = int(m.get("duration") or 0)
        px.req("GET", "/:/progress", want="text", key=m["ratingKey"], identifier=ident, time=int(dur * frac),
               state="stopped")
    print(f"{srv} plex: watch state set")


# ------------------------------------------------------------------ check
def label(it):
    if it.get("Type") == "Episode" or it.get("type") == "episode":
        name = it.get("SeriesName") or it.get("grandparentTitle")
        s = it.get("ParentIndexNumber", it.get("parentIndex"))
        e = it.get("IndexNumber", it.get("index"))
        return f"{name} S{s:02d}E{e:02d}"
    return it.get("Name") or it.get("title")


def check_mb(srv, kind, env):
    p = f"{srv.upper()}_{kind.upper()}_"
    mb = MB(kind, SERVERS[srv])
    mb.token = env[p + "API_KEY"]   # what MediaCache uses
    for u in mb.req("GET", "/Users"):
        uid = u["Id"]
        res = mb.req("GET", f"/Users/{uid}/Items/Resume", Limit=50, Fields="Path", MediaTypes="Video")
        nxt = mb.req("GET", "/Shows/NextUp", UserId=uid, Limit=50, Fields="Path")
        legacy = ""
        if kind == "emby":  # Emby 4.10: NextUp is empty without LegacyNextUp; the next episode sits in Resume instead
            lg = mb.req("GET", "/Shows/NextUp", UserId=uid, Limit=50, LegacyNextUp="true")
            legacy = f"  nextup(legacy)=[{', '.join(label(i) for i in lg['Items'])}]"
        played = mb.req("GET", f"/Users/{uid}/Items", Recursive="true", IncludeItemTypes="Movie,Episode",
                        IsPlayed="true", Limit=0)
        print(f"  {srv:5} {kind:8} {u['Name']:9} played={played.get('TotalRecordCount', 0):<2} "
              f"resume=[{', '.join(label(i) for i in res['Items'])}]  nextup=[{', '.join(label(i) for i in nxt['Items'])}]{legacy}")


def check_plex(srv, env):
    px = Plex(SERVERS[srv])
    deck = px.mc("/library/onDeck").get("Metadata") or []
    played = 0
    for key in plex_sections(px).values():
        for typ in (1, 4):
            played += sum(1 for m in px.mc(f"/library/sections/{key}/all", type=typ).get("Metadata") or [] if m.get("viewCount"))
    print(f"  {srv:5} plex     (admin)   played={played:<2} ondeck=[{', '.join(label(m) for m in deck)}]")


def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else "check"
    which = sys.argv[2:] or list(SERVERS)
    env = load_env()
    for srv in which:
        try:
            if cmd == "setup":
                for kind in ("emby", "jellyfin"):
                    setup_mb(srv, kind, env)
                    save_env(env)
                setup_plex(srv, env)
                save_env(env)
            if cmd in ("setup", "reset"):
                for kind in ("emby", "jellyfin"):
                    reset_mb(srv, kind, env)
                reset_plex(srv, env)
            if cmd in ("setup", "reset", "check"):
                for kind in ("emby", "jellyfin"):
                    check_mb(srv, kind, env)
                check_plex(srv, env)
        finally:
            save_env(env)


if __name__ == "__main__":
    main()
