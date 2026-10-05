#!/usr/bin/env bash
# Runs the cases of the Raoh Specification on raoh-php and checks the result against
# conformance/conformance.json with the raoh-verify of the commit conformance/spec.lock pins.
# Writes, under conformance/target/:
#
#   runner-result.json       what raoh-php gave for each case it can run
#   conformance-report.json  what raoh-verify made of it
#
# Exits with raoh-verify's status: 0 when no profile is non-conformant, 1 when one is, 2 when the
# input cannot be trusted. Any other failure exits non-zero too.
#
# Needs git, jq, Go and PHP. RAOH_SPECIFICATION_DIR names a checkout to use instead of cloning
# one; it has to be at the pinned commit with no changes to what the commit holds.
# RAOH_PHP_VERSION names the version the result reports, as the release workflow sets it; without
# it the result reports develop's branch alias.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
OUT="$ROOT/conformance/target"
LOCK="$ROOT/conformance/spec.lock"
REPOSITORY="$(jq -er .repository "$LOCK")"
REVISION="$(jq -er .revision "$LOCK")"

mkdir -p "$OUT"
# What this run writes, removed first, so that a run that fails before writing them never leaves
# an earlier run's result or report to be read as this one's.
rm -f "$OUT/runner-result.json" "$OUT/conformance-report.json"

if [[ -n "${RAOH_SPECIFICATION_DIR:-}" ]]; then
    SPEC="$(cd "$RAOH_SPECIFICATION_DIR" && pwd)"
else
    SPEC="$OUT/raoh-specification"
    if [[ ! -d "$SPEC/.git" ]]; then
        git clone --quiet "https://github.com/$REPOSITORY.git" "$SPEC"
    fi
    if ! git -C "$SPEC" cat-file -e "$REVISION^{commit}" 2>/dev/null; then
        git -C "$SPEC" fetch --quiet origin
    fi
    git -C "$SPEC" -c advice.detachedHead=false checkout --quiet --detach "$REVISION"
fi

# The suite and the verifier are both read from this checkout, so it has to be the pinned revision
# exactly.
HEAD="$(git -C "$SPEC" rev-parse HEAD)"
if [[ "$HEAD" != "$REVISION" ]]; then
    echo "ERROR: $SPEC is at $HEAD, but conformance/spec.lock pins $REVISION" >&2
    exit 1
fi
DIRTY="$(git -C "$SPEC" status --porcelain --untracked-files=all -- \
    specification.json spec catalog schema suite cmd internal go.mod go.sum)"
if [[ -n "$DIRTY" ]]; then
    echo "ERROR: $SPEC has changes the pinned revision does not:" >&2
    echo "$DIRTY" >&2
    exit 1
fi

# The revision of raoh-php the result describes, read before anything runs. A change to a tracked
# file, or a file git neither ignores nor tracks, means the runner runs something no commit is.
IMPLEMENTATION_REVISION="$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || echo uncommitted)"
if [[ -n "$(git -C "$ROOT" status --porcelain --untracked-files=all -- . ':(exclude)conformance/target')" ]]; then
    IMPLEMENTATION_REVISION="$IMPLEMENTATION_REVISION-dirty"
fi

# The verifier of the pinned revision, not whichever raoh-verify is on PATH.
(cd "$SPEC" && go build -o "$OUT/raoh-verify" ./cmd/raoh-verify)
DIGEST="$("$OUT/raoh-verify" manifest "$SPEC")"

php "$ROOT/conformance/runner.php" \
    --spec "$SPEC" \
    --revision "$REVISION" \
    --manifest-digest "$DIGEST" \
    --implementation-revision "$IMPLEMENTATION_REVISION" \
    --out "$OUT/runner-result.json"

"$OUT/raoh-verify" verify \
    --spec "$SPEC" \
    --result "$OUT/runner-result.json" \
    --conformance "$ROOT/conformance/conformance.json" \
    -o "$OUT/conformance-report.json"
