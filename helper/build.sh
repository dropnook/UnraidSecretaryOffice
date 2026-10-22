#!/bin/bash
# Builds the USO network's helper reproducibly: helper/dist/uso-net-<version>-linux-amd64 — the same bytes on every
# machine (the exact Go of go.mod's go line, the modules of go.sum, -trimpath, no build id, no cgo), so the
# SHA-256 the office pins (helper/uso-net.sha256, USONET_HELPER in agent/lib/usonet.php) can be checked by anyone.
#
#   bash helper/build.sh            with the go on PATH when it is the pinned one, else in Docker's golang:<pinned>
#   bash helper/build.sh --check    and compare with helper/uso-net.sha256 (exit 1 when it differs)
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
go_version=$(sed -n "s/^go \([0-9.]*\)$/\1/p" "$here/go.mod")     # an exact version (go 1.27.3): the toolchain itself
version=$(sed -n 's/^const Version = "\(.*\)"$/\1/p' "$here/main.go")
out="uso-net-$version-linux-amd64"
mkdir -p "$here/dist"
build='CGO_ENABLED=0 GOOS=linux GOARCH=amd64 GOAMD64=v1 GOFLAGS=-mod=readonly go build -trimpath -buildvcs=false -tags ts_omit_logtail -ldflags="-s -w -buildid=" -o dist/'"$out"' .'
if command -v go >/dev/null 2>&1 && [[ "$(go env GOVERSION)" == "go$go_version" ]]; then
    (cd "$here" && eval "$build")
else
    cache=()        # HELPER_GOCACHE=<folder>: the modules and the build cache kept between runs
    [[ -n "${HELPER_GOCACHE:-}" ]] && cache=(-v "$HELPER_GOCACHE/mod:/go/pkg/mod" -v "$HELPER_GOCACHE/build:/root/.cache/go-build")
    docker run --rm -v "$here":/src "${cache[@]}" -w /src -e GOTOOLCHAIN=local "golang:$go_version" sh -c "$build"
fi
sum=$(cd "$here/dist" && sha256sum "$out")
echo "$sum"
if [[ "${1:-}" == --check ]]; then
    if [[ "$sum" != "$(cat "$here/uso-net.sha256")" ]]; then
        echo "differs from the pinned helper/uso-net.sha256: $(cat "$here/uso-net.sha256")" >&2
        exit 1
    fi
    echo "same as the pinned helper/uso-net.sha256"
fi
