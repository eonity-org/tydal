#!/usr/bin/env bash
# Thin wrapper → tools/clients/vault-apps.sh. See tools/README.md.
exec "$(dirname "${BASH_SOURCE[0]}")/tools/clients/vault-apps.sh" "$@"
