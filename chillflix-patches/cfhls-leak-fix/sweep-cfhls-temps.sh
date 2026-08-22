#!/bin/bash
# Sweep orphaned Vuflix stream-proxy temps left when PHP-FPM kills workers
# (request_terminate_timeout) before finally/unlink runs.
# Safe: only deletes cf* temp prefixes older than 10 minutes.
set -euo pipefail
find /tmp -maxdepth 1 -type f \( \
  -name 'cfhls_*' -o -name 'cfin_*' -o -name 'cfout_*' -o \
  -name 'cfget_*' -o -name 'cfwarm_*' \
\) -mmin +10 -delete 2>/dev/null || true
