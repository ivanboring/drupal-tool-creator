# Shared helpers for the site scripts. Sourced, never executed. They run inside the ddev web
# container (`ddev exec bash site/...`, or through `tc`), where composer and drush are local.

TC_ROOT="${TC_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
TC_SITE_DIR="$TC_ROOT/drupal"
TC_BASELINES="$TC_ROOT/baselines"
TC_SITE_BASELINE="$TC_BASELINES/site"
DRUSH="$TC_SITE_DIR/vendor/bin/drush"

say() { [ "${TC_QUIET:-0}" = 1 ] || echo "$@"; }
die() { echo "!! $*" >&2; exit 1; }

[ "${IS_DDEV_PROJECT:-}" = true ] || die "run this inside the web container: ddev exec bash $0 ..."

# site_files0: NUL-separated list of the Drupal tree's files relative to the cwd, without "./".
# Skips the public/private files dirs, which are emptied on every reset anyway.
site_files0() {
  find . -type f -not -path './web/sites/*/files/*' -printf '%P\0' 2>/dev/null
}

# Run a command in the Drupal dir with stdin closed; quiet mode keeps output in logs/site.log.
site_run() {
  local log="$TC_ROOT/logs/site.log"
  mkdir -p "$(dirname "$log")"
  if [ "${TC_QUIET:-0}" = 1 ]; then
    if ! ( cd "$TC_SITE_DIR" && "$@" ) >>"$log" 2>&1 </dev/null; then
      echo "!! failed: $* (see logs/site.log)" >&2
      tail -15 "$log" | sed 's/^/     /' >&2
      return 1
    fi
  else
    ( cd "$TC_SITE_DIR" && "$@" ) </dev/null
  fi
}

db_dump() {  # db_dump <file.sql.gz>
  ( cd "$TC_SITE_DIR" && "$DRUSH" sql:dump --extra-dump=--no-tablespaces ) | gzip -1 > "$1"
}

db_load() {  # db_load <file.sql.gz>
  ( cd "$TC_SITE_DIR" && "$DRUSH" sql:drop -y >/dev/null && gunzip -c "$1" | bash -c "$("$DRUSH" sql:connect)" )
}
