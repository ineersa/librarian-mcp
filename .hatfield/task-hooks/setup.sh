#!/usr/bin/env bash
set -euo pipefail
: "${HATFIELD_PROJECT_ROOT:?}" "${HATFIELD_TASK_WORKTREE:?}"
cd -- "$HATFIELD_TASK_WORKTREE"
for directory in vendor .hatfield .hatfield/extensions; do
    if [[ -L "$directory" ]]; then
        printf 'Refusing symlinked setup destination: %s\n' "$directory" >&2
        exit 1
    fi
done
# Bootstrap the locked Castor entry point before its native dependency/runtime setup.
mkdir -p -- vendor .hatfield/extensions
cp -a -- "$HATFIELD_PROJECT_ROOT/vendor/." vendor/
# Local settings are deliberately untracked. Provision new operational workspaces
# without overwriting an existing file or copying credentials, OM data or .env.local.
for file in .hatfield/settings.yaml .hatfield/extensions/composer.json .hatfield/extensions/composer.lock; do
    if [[ ! -e "$file" && ! -L "$file" ]]; then
        cp -p -- "$HATFIELD_PROJECT_ROOT/$file" "$file"
    fi
done
vendor/bin/castor tooling:prepare
vendor/bin/castor app:composer -- --working-dir=.hatfield/extensions install --no-interaction --no-scripts
