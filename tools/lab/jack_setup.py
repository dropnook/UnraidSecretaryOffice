#!/usr/bin/env python3
"""Lab only: set Jack Streamer up against a lab server's Emby + Jellyfin + Plex, as the page's setup would save it.
  python3 -I tools/lab/jack_setup.py <tower|test3> <pool path> <budget GB> [emby,jellyfin,plex]
Keys come from lab-secrets.env; the request file lives 0600 in the server's /tmp and is removed after."""
import json, os, subprocess, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import lab

srv, pool, budget = sys.argv[1], sys.argv[2], int(sys.argv[3])
kinds = (sys.argv[4] if len(sys.argv) > 4 else "emby,jellyfin,plex").split(",")
host = {"tower": "uso-test", "test3": "root@192.168.7.197"}[srv]
ip = lab.SERVERS[srv]
env = lab.load_env()
P = srv.upper()
SPEC = {"emby": (f"http://{ip}:8096", env[f"{P}_EMBY_API_KEY"], "/mnt/media"),
        "jellyfin": (f"http://{ip}:8097", env[f"{P}_JELLYFIN_API_KEY"], "/data/media"),
        "plex": (f"http://{ip}:32400", env[f"{P}_PLEX_TOKEN"], "/media")}


def ask(action, body, wait=120):
    path = f"/tmp/lab-ask-{os.getpid()}.json"
    subprocess.run(["ssh", host, f"umask 077; cat > {path}"], input=json.dumps(body).encode(), check=True)
    out = subprocess.run(["ssh", host, f"php /tmp/ask.php {action} @{path} {wait}; rm -f {path}"], capture_output=True, check=True)
    return json.loads(out.stdout)


instances, libs, types, users = [], [], {}, []
for k in kinds:
    url, key, root = SPEC[k]
    r = ask("jack.connect", {"url": url, "api_key": key, "type": k})
    if not r.get("ok"):
        sys.exit(f"{k}: {r.get('error')}")
    maps = {}
    for lib in r["libraries"]:
        for loc in lib["locations"]:
            maps[loc] = "/mnt/user/media" + loc[len(root):]
        if lib["name"] not in libs:
            libs.append(lib["name"])
            types[lib["name"]] = lib["type"]
    users += [u["id"] for u in r["users"]]
    print(f"{k}: {r['server']}  libraries={[l['name'] for l in r['libraries']]}  users={[u['name'] for u in r['users']]}")
    instances.append({"type": k, "servername": f"{srv}-{k}", "url": url, "api_key": key, "path_mappings": maps})

settings = {"instances": instances, "libraries": libs, "library_types": types, "valid_users": users, "user_budgets": {},
            "cache_path": pool, "cache_budget": f"{budget}G", "movie_share_percent": 50, "max_episodes_per_series": 0,
            "max_resume_movies": 10, "max_resume_series": 10, "max_resume_items": 10, "max_favorite_series": 10,
            "use_next_up": True, "movie_mode": "folder", "return_to_origin": False, "array_source": "user0"}
r = ask("jack.save", {"settings": settings})
print("save:", r.get("ok"), r.get("error"), "configured:", (r.get("state") or {}).get("configured"))
