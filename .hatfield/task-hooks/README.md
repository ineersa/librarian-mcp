# Librarian task workflow setup

Workflow behavior comes from the published `ineersa/hatfield-ext-task-workflow`
package (v0.0.50 or newer) and the `task-workflow` skill it installs. The
extension's README and issue templates are installed next to that skill.

## Local configuration

Hatfield settings, extension manifests and locks, managed skills and runtime data
stay local and are gitignored. Only these project-owned hooks and the shared issue
templates (`.github/ISSUE_TEMPLATE/`) are versioned.

```bash
vendor/bin/castor app:composer -- --working-dir=.hatfield/extensions require \
  'ineersa/hatfield-ext-task-workflow:^0.0.50' --with-dependencies --no-interaction --no-scripts
```

Merge the following into `.hatfield/settings.yaml` and keep the extensions and
settings that are already there. Reuse the account's existing **Hatfield Status**
field; do not create a duplicate Workflow field.

```yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension
  settings:
    task_workflow:
      repository: ineersa/librarian-mcp
      remote: origin
      base_branch: main
      worktree_root: ../librarian-mcp-worktrees
      workflow_field: Hatfield Status
      merge_method: merge
      hooks:
        setup:
          script: .hatfield/task-hooks/setup.sh
          timeout_seconds: 900
        validate:
          script: .hatfield/task-hooks/validate.sh
          timeout_seconds: 1800
        cleanup:
          script: .hatfield/task-hooks/cleanup.sh
          timeout_seconds: 300
```

## Lifecycle hooks

The hooks delegate to existing Castor tasks and add no separate Docker or QA runner.

- **Setup:** in a new task worktree, copies `vendor/` from the primary checkout,
  then runs `tooling:prepare` and installs the locked extension dependencies.
  Local Hatfield settings and extension manifests are copied only if the destination
  is missing. It never copies `.env.local`, `.env.prod.local`, credentials, OM
  databases, session journals or `data*/` storage.
- **Validate:** runs `vendor/bin/castor check`. This happens in the task worktree at
  review, and in the primary checkout after merge at completion. A failure stays a
  failure. Read `var/qa/*/result.json` instead of full reports.
- **Cleanup:** from the task worktree itself, runs `browser:stack:stop` and
  `dev:stack:stop`. Both stop only that checkout's stacks; the shared proxy and
  persistent data are left alone. A task that never started is a no-op. If the
  checkout or its Castor is missing, cleanup refuses rather than guessing which
  containers to stop.

Owner review stays required, and merges happen only with explicit authorization.
