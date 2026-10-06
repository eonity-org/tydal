#!/usr/bin/env bash
# Thin wrapper → tools/deploy/install.sh. See tools/deploy/README.md.
#   ./install.sh <cloud|ollama-host|ollama-docker> <host|docker> [--infra=own|shared] [--name=NAME] [-f] [--fresh]
# (the --infra/--name flags go to configure.sh; --help for every flag.)
exec "$(dirname "${BASH_SOURCE[0]}")/tools/deploy/install.sh" "$@"
