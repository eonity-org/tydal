#!/usr/bin/env bash
# Thin wrapper → tools/deploy/start.sh. See tools/deploy/README.md.
# No arguments: names and ports come from the root .env written by configure.sh
# (tydal_app :8000 / Vite :3005 by default; tydal_NAME_* and offset ports on a
# shared-infrastructure installation).
exec "$(dirname "${BASH_SOURCE[0]}")/tools/deploy/start.sh" "$@"
