#!/usr/bin/env bash
# Thin wrapper → tools/deploy/configure.sh. See tools/deploy/README.md.
exec "$(dirname "${BASH_SOURCE[0]}")/tools/deploy/configure.sh" "$@"
