#!/usr/bin/env bash
# Thin wrapper → tools/deploy/install.sh. See tools/deploy/README.md.
exec "$(dirname "${BASH_SOURCE[0]}")/tools/deploy/install.sh" "$@"
