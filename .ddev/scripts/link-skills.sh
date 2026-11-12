#!/usr/bin/env bash
# Link every skill so each coding tool finds it: Claude Code reads .claude/skills, Codex and
# OpenCode read .agents/skills. The tool creator's own skills live in .agents/skills (canonical);
# the tool module's two skills are linked from the installed module, so they always match the
# version in the site.
set -euo pipefail
ROOT="${DDEV_APPROOT:-$(cd "$(dirname "$0")/../.." && pwd)}"
cd "$ROOT"
mkdir -p .claude/skills .agents/skills
for d in .agents/skills/*/; do
  n="$(basename "$d")"
  [ -L ".agents/skills/$n" ] && continue
  ln -sfn "../../.agents/skills/$n" ".claude/skills/$n"
done
for n in create-tool-plugins use-drupal-tools; do
  ln -sfn "../../drupal/web/modules/contrib/tool/.agents/skills/$n" ".agents/skills/$n"
  ln -sfn "../../drupal/web/modules/contrib/tool/.agents/skills/$n" ".claude/skills/$n"
done
