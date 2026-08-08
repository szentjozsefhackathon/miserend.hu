#!/usr/bin/env bash
#
# #652: kiírja az aktuális commit rövid hashét a webapp/fajlok/git_hash fájlba.
#
# Ebből olvassa a \Html\Html::getGitHash(), és ez jelenik meg a lábléc­ben
# („verzió: abc1234"), hogy egy hibajelentésnél látszódjon, benne van-e már a javítás.
#
# Eddig ezt egy git post-checkout hookra bíztuk. A hookok viszont NEM verziókövetettek,
# és a szerveren a deploy `git pull`-t használ, ami post-checkoutot nem is indít — ezért
# a fájl élesben soha nem jött létre, és a lábléc üresen maradt.
#
# A deploy-workflowk ezt a szkriptet hívják; fejlesztéskor kézzel futtatható:
#   bash scripts/write-git-hash.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="$REPO_ROOT/webapp/fajlok/git_hash"

if ! git -C "$REPO_ROOT" rev-parse --git-dir >/dev/null 2>&1; then
  echo "write-git-hash: nem git-munkakönyvtár ($REPO_ROOT) — kihagyva." >&2
  exit 0
fi

# A getGitHash() 7-8 alfanumerikus karaktert vár, ezért fixen 8-at kérünk:
# a `--short` alapértéke a repó méretétől függően nőhet.
HASH="$(git -C "$REPO_ROOT" rev-parse --short=8 HEAD)"

mkdir -p "$(dirname "$TARGET")"
printf '%s' "$HASH" > "$TARGET"
echo "write-git-hash: $HASH -> $TARGET"
