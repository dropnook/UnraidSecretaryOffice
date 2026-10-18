A server like USOTest3VM (the resilience programme's plain server, 2026-10-18): Docker off (no container, no picture,
no stack — templates and appdata folders from earlier stay: Ms. Dustdevil's bubble must not count them), the VM service
off, no backup run yet (no package), no media server, a quiet syslog, Ms. Moverelli with only an array share (no pool).
Made from the stub states one folder up (jq), for what must not be said on such a server:
`node tools/scenarios/page.mjs --states tests/ui/states/docker-off <desk> --not <desk>.greet.N`,
`bash tools/ui-clicks.sh --states tests/ui/states/docker-off --only cleanup,restore,jack,logs,moverelli`, testGreetTruth.
