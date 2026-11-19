#!/usr/bin/env bash
set -euo pipefail
# Never-started TODO tasks have no checkout or owned stacks to stop.
if [[ -z "${HATFIELD_TASK_WORKTREE:-}" && "${HATFIELD_TASK_SETUP_ATTEMPTED:-}" == "0" ]]; then
    printf 'No task workspace was provisioned; no owned stacks to clean.\n'
    exit 0
fi
: "${HATFIELD_PROJECT_ROOT:?}" "${HATFIELD_TASK_WORKTREE:?}"
if [[ "$HATFIELD_TASK_WORKTREE" == "$HATFIELD_PROJECT_ROOT" ]]; then
    printf 'Refusing to clean the primary checkout as a task workspace.\n' >&2
    exit 1
fi
# Never fall back to the primary cwd or guess container names after workspace loss.
# A refusal keeps Hatfield recovery pending instead of claiming unverified cleanup.
if [[ ! -d "$HATFIELD_TASK_WORKTREE" || ! -x "$HATFIELD_TASK_WORKTREE/vendor/bin/castor" ]]; then
    printf 'Restore the task checkout and its locked Castor before retrying owned-stack cleanup.\n' >&2
    exit 1
fi
cd -- "$HATFIELD_TASK_WORKTREE"
vendor/bin/castor browser:stack:stop
vendor/bin/castor dev:stack:stop
