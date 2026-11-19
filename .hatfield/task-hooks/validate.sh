#!/usr/bin/env bash
set -euo pipefail
# Hatfield sets cwd to the task at review, and the merged primary at completion.
exec vendor/bin/castor check
