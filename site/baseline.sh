#!/usr/bin/env bash
# Capture or restore a baseline of the Drupal site: dependencies, code, and database.
#
#   site/baseline.sh capture <dir>   snapshot drupal/ as it is now into <dir>
#   site/baseline.sh restore <dir>   put drupal/ and the database back exactly as <dir> recorded
#
# A baseline directory holds:
#   composer.json / composer.lock  the dependency set
#   manifest.sha256                sha256 of every file under drupal/ (except the files dirs)
#   sitefiles.tar.gz               verbatim copy of the files composer does not own
#   db.sql.gz                      the database
#
# Two kinds exist: the clean site (baselines/site, captured by `ddev setup`) and one per module
# (baselines/modules/<module>, captured by `tc module prepare`: the clean site plus that module
# installed and enabled). Every eval restores one of these first.
#
# Restore deletes every file under drupal/ that the manifest does not list, including
# web/modules/custom/*. Anything not saved as a variant under workspace/ is lost, which is why
# `tc` autosaves the tool module before it calls this.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

ACTION="${1:?usage: baseline.sh capture|restore <dir>}"
DIR="${2:?usage: baseline.sh capture|restore <dir>}"
case "$DIR" in /*) ;; *) DIR="$TC_ROOT/$DIR" ;; esac
S="$TC_SITE_DIR"
[ -f "$S/composer.json" ] || die "no Drupal site in $S (run: ddev setup)"

capture() {
  mkdir -p "$DIR"
  say "-- capturing baseline into ${DIR#$TC_ROOT/}"
  cp -f "$S/composer.json" "$DIR/composer.json"
  cp -f "$S/composer.lock" "$DIR/composer.lock"
  ( cd "$S" && site_files0 | sort -z | xargs -0 sha256sum ) > "$DIR/manifest.sha256"
  ( cd "$S" && tar czf "$DIR/sitefiles.tar.gz" \
      --exclude='./web/sites/*/files' --exclude='./vendor' --exclude='./web/core' \
      --exclude='./web/modules/contrib' --exclude='./web/themes/contrib' \
      --exclude='./web/profiles/contrib' --exclude='./web/libraries' . )
  site_run "$DRUSH" cr
  db_dump "$DIR/db.sql.gz"
  date -u +%Y-%m-%dT%H:%M:%SZ > "$DIR/captured-at"
  say "   $(wc -l < "$DIR/manifest.sha256") files hashed, db $(du -h "$DIR/db.sql.gz" | cut -f1)"
}

restore() {
  for f in composer.json composer.lock manifest.sha256 sitefiles.tar.gz db.sql.gz; do
    [ -f "$DIR/$f" ] || die "baseline ${DIR#$TC_ROOT/} is missing $f"
  done
  say "-- restoring drupal/ + database to ${DIR#$TC_ROOT/}"

  # 1. dependencies: `composer install` against the baseline lock removes packages added since.
  if ! cmp -s "$DIR/composer.lock" "$S/composer.lock" || ! cmp -s "$DIR/composer.json" "$S/composer.json"; then
    cp -f "$DIR/composer.json" "$S/composer.json"
    cp -f "$DIR/composer.lock" "$S/composer.lock"
    site_run composer install --no-interaction --no-progress
  fi

  # 2. the site's own files back to their baseline bytes
  tar xzf "$DIR/sitefiles.tar.gz" -C "$S"

  # 3. remove every file the baseline does not know about (custom modules, stray files)
  local pruned=0 rel
  while IFS= read -r -d '' rel; do
    rm -f "$S/$rel"; pruned=$((pruned + 1))
  done < <(
    comm -13 <(cut -d' ' -f3- "$DIR/manifest.sha256" | sort) \
             <( cd "$S" && site_files0 | tr '\0' '\n' | sort ) | tr '\n' '\0'
  )
  ( cd "$S" && find . -mindepth 1 -type d -empty -not -path './web/sites/*/files*' \
      -not -path './web/modules/custom' -delete 2>/dev/null || true )
  mkdir -p "$S/web/modules/custom"
  say "   pruned $pruned file(s)"

  # 4. repair composer-managed code edited in place (a patched core or contrib file)
  local drift
  drift="$( ( cd "$S" && sha256sum --quiet -c "$DIR/manifest.sha256" 2>/dev/null ) \
      | sed 's/: FAILED.*$//' | grep -Ev '^(vendor/composer/|composer\.lock$|vendor/autoload\.php$)' || true )"
  if [ -n "$drift" ]; then
    say "   code differs from baseline, reinstalling composer trees:"
    echo "$drift" | head -10 | sed 's/^/     /'
    ( cd "$S" && rm -rf vendor web/core web/modules/contrib web/themes/contrib web/profiles/contrib web/libraries )
    site_run composer install --no-interaction --no-progress
    tar xzf "$DIR/sitefiles.tar.gz" -C "$S"
  fi

  # 5. public files, 6. database
  if [ -d "$S/web/sites/default/files" ]; then
    find "$S/web/sites/default/files" -mindepth 1 -delete 2>/dev/null || true
  fi
  db_load "$DIR/db.sql.gz"
  site_run "$DRUSH" cr
  say "-- restored"
}

case "$ACTION" in
  capture) capture ;;
  restore) restore ;;
  *) die "unknown action $ACTION" ;;
esac
