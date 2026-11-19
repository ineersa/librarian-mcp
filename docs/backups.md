# Daily backups

The Messenger worker uploads Librarian's untracked data to Google Cloud Storage once a day. Retention and the success email work the same way as in the operator's earlier `cron-jobs` backup commands.

## What is backed up

Each run creates one archive per source, under its own prefix:

| Source | Object | Content |
|---|---|---|
| Librarian PostgreSQL | `librarian/postgres/postgres_<stamp>.dump` | `pg_dump --format=custom --no-owner` of the Librarian database, which includes the canonical OM observations and reflections |
| Hatfield, per registered project | `hatfield/<project>/hatfield_<project>_<stamp>.tar.gz` | The project's `.hatfield/` directory without disposable files (see below), plus a consistent snapshot of `state.sqlite` |
| Claude Code, per registered project | `claude/<project>/claude_<project>_<stamp>.tar.gz` | The project's transcript directory under the read-only Claude mount |

`<project>` is `<id>-<slug>`: the registration's stable ID plus the lowercase ASCII slug of its name. Different names with the same slug therefore never share objects or retention. `<stamp>` is `Y_m_d_H_i_s` in UTC. Derived indexes are not backed up because they can be rebuilt.

These paths are excluded from the Hatfield archive:
- `cache/`, `logs/`, `tmp/`, `locks/` and `*.lock`
- `extensions/` (vendor code)
- `extensions-data/observational-memory/` (replaced by Librarian OM)
- in each session, `runtime/`, `messenger-queue.sqlite` and `history-index.sqlite`

Session journals (`sessions/*/events.jsonl`), child runs, artifacts and attachments are kept. A custom `sessions.path` inside the project root (the directory discovery stored for the project) is archived too, at its path relative to the root and with the same per-session exclusions. A sessions directory outside the project root has no restorable place in the archive, so it fails the backup.

A registered project whose root is missing or unreadable fails the run. A readable project with neither `.hatfield` nor a Claude transcript directory is skipped.

`state.sqlite` is a live WAL database, and SQLite cannot open it from a read-only mount. The backup copies the main file and its `-wal` into the work directory and retries if either changes during the copy. The copy must pass `quick_check`, and SQLite's online backup API then writes the archived snapshot. A Hatfield session that is writing at that moment can still race the copy. For an exact snapshot, stop Hatfield first. Source files are only ever read.

## Schedule, status and reruns

- The `cron` schedule (`scheduler_cron`) queues the backup on the `cron` transport every 30 minutes. If a backup already **succeeded today**, the run is skipped without recording a row. "Today" is the day in `app.display_timezone`.
- The worker consumes `scheduler_cron` and `cron` before `async` and `scheduler_default`. Messenger checks receivers in that priority order, so a busy indexing queue cannot starve backups.
- A failed or missing run executes on the next tick. A failed day therefore retries every 30 minutes and uploads every source again; retention keeps storage bounded. A Symfony Lock prevents overlapping runs. A `running` row left behind by a stopped worker is marked failed.
- **Admin › Operations › Cron runs** lists runs read-only: status, whether the run was forced, timing, an error summary, and the uploaded objects on the detail page. **Run backup now** queues a forced run. The action is a POST protected by a session CSRF token.
- `vendor/bin/castor app:console -- app:cron:run backup [--force]` queues the same message. `--force` bypasses the "already succeeded today" check. The job always runs in the worker, because only the worker has the key file and work directory mounted.

## Retention and email

- **Retention:** after each upload, the run lists that prefix, sorts the objects by `timeCreated`, and deletes all but the **3 newest**. Each prefix is handled separately.
- **Email:** one HTML email from `MAILER_FROM` to `MAILER_TO` after a fully successful run, listing every uploaded `gs://` object. A failed run sends no email; the cron-run row records the failure. Empty `MAILER_FROM` or `MAILER_TO` skips the email.

## Configuration

Set these in `.env.local` (dev) or `.env.prod.local` (prod, see `.env.prod.local.dist`). Never commit them.

| Variable | Meaning |
|---|---|
| `GOOGLE_SERVICE_ACCOUNT_JSON_PATH` | Host path of the service-account key. Compose mounts it read-only into the worker at the same path. It is never copied into the image. |
| `GCS_BUCKET_NAME` | The target bucket: `data-backups-local` for local runs, `librarian-prod` in production |
| `MAILER_DSN` | `mailjet+api://ACCESS_KEY:SECRET_KEY@default`. The default is `null://null`. |
| `MAILER_FROM`, `MAILER_TO` | Success email sender and recipient |
| `BACKUP_WORK_DIR` | Directory for temporary archives, relative to the project directory. Archives are deleted after upload. The default is `var/backup-work` (`data-prod/backup-work` in prod). |

If the key path or bucket is empty, or the key file is unreadable, the run fails with a clear error and uploads nothing. Browser tests use a local-directory storage stand-in (`BACKUP_LOCAL_STORAGE_DIR`) and the null mailer, and never call GCS or Mailjet.

## Restoring

Restore into scratch locations only. Never restore over live data.

1. Download the objects you need, for example with `gcloud storage cp gs://<bucket>/<object> <scratch-dir>/`.
2. **PostgreSQL:** run `createdb restore_check`, then `pg_restore --no-owner -d restore_check postgres_<stamp>.dump`, and compare table row counts with the source. Only point Librarian at a restored database deliberately, as an explicit recovery step.
3. **Hatfield:** `tar -xzf hatfield_<project>_<stamp>.tar.gz -C <scratch-dir>` recreates `.hatfield/` with `state.sqlite` and the session directories. If `state.sqlite` is missing, Hatfield rebuilds basic catalog rows from `events.jsonl`, but loses renames, pending work and child state.
4. **Claude:** `tar -xzf claude_<project>_<stamp>.tar.gz -C <scratch-dir>` recreates the transcript directory. Its name starts with `-`, so use `--` or `./` when you handle it with other tools.
