#!/usr/bin/env bash
# Thin wrapper → tools/dev/seed-vault.sh. See tools/README.md.
exec "$(dirname "${BASH_SOURCE[0]}")/tools/dev/seed-vault.sh" "$@"
