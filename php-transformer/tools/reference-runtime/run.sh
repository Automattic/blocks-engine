#!/usr/bin/env bash
# Run a command from the repository root inside the reference PHP runtime.
#
#   php-transformer/tools/reference-runtime/run.sh composer --working-dir=php-transformer test
#
# The image is built once per Dockerfile revision. The checkout is mounted at
# its own path so recorded and reported paths match a host run.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(git -C "$here" rev-parse --show-toplevel)"
tag="blocks-engine-reference-runtime:$(sha256sum "$here/Dockerfile" | cut -c1-12)"

if ! docker image inspect "$tag" >/dev/null 2>&1; then
    docker build --quiet --tag "$tag" "$here" >/dev/null
fi

common="$(git -C "$root" rev-parse --path-format=absolute --git-common-dir)"
mounts=(--volume "$root:$root")
case "$common" in
    "$root"/*) ;;
    *) mounts+=(--volume "$common:$common") ;;
esac

exec docker run --rm --user "$(id -u):$(id -g)" --env HOME=/tmp --env COMPOSER_HOME=/tmp/composer \
    "${mounts[@]}" --workdir "$root" "$tag" "$@"
