#!/usr/bin/env bash
# Build the Drupal site in drupal/ and capture its clean baseline. Idempotent. Run by `ddev setup`
# inside the web container.
#
# The clean baseline is Drupal 11 standard (admin/admin) with:
#   drush            the tool:* commands and `drush generate plugin:tool`
#   drupal/tool      the Tool API, Tool Explorer, and the two agent skills it ships
#   drupal/tool_belt code only, not enabled: worked Tool examples the generator skill reads
#   drupal/core-dev  phpunit, phpcs, phpstan, so the generated tests run
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "$TC_SITE_DIR"

if [ ! -f composer.json ]; then
  say "-- creating drupal/recommended-project:^11 in drupal/"
  composer create-project "drupal/recommended-project:^11" /tmp/tc-drupal --no-interaction --no-install
  cp -a /tmp/tc-drupal/. "$TC_SITE_DIR/" && rm -rf /tmp/tc-drupal
  composer install --no-interaction
fi

# Beta/alpha releases of the Tool API, and the plugins core-dev brings (non-interactive composer
# refuses plugins that are not allowed explicitly).
composer config minimum-stability dev
composer config prefer-stable true
for p in dealerdirect/phpcodesniffer-composer-installer phpstan/extension-installer php-http/discovery tbachert/spi; do
  composer config --no-plugins "allow-plugins.$p" true
done

has() { composer show "$1" >/dev/null 2>&1; }
has drush/drush       || composer require -W "drush/drush:^13" --no-interaction
has drupal/tool       || composer require -W "drupal/tool:^1.0@beta" --no-interaction
has drupal/tool_belt  || composer require -W "drupal/tool_belt:^1.0@alpha" --no-interaction \
                      || echo "-- tool_belt could not be added (optional, continuing)"
has drupal/core-dev   || composer require --dev -W "drupal/core-dev:^11" --no-interaction

if ! "$DRUSH" status --field=bootstrap 2>/dev/null | grep -qi successful; then
  say "-- installing Drupal (standard, admin/admin)"
  "$DRUSH" site:install standard --account-name=admin --account-pass=admin --site-name="Tool creator" -y
fi
"$DRUSH" pm:enable tool tool_explorer -y
mkdir -p web/modules/custom

bash "$TC_ROOT/.ddev/scripts/link-skills.sh"
bash "$TC_ROOT/site/baseline.sh" capture "$TC_SITE_BASELINE"
say "== ready: https://${DDEV_HOSTNAME:-drupal-tool-creator.localhost} (admin/admin)"
