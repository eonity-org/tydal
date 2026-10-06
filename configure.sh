#!/usr/bin/env bash
# Thin wrapper → tools/deploy/configure.sh. See tools/deploy/README.md.
#   ./configure.sh <cloud|ollama-host|ollama-docker> <host|docker> [--infra=own|shared] [--name=NAME] [-f]
# (--infra=shared --name=NAME: use another checkout's MySQL/ES/Redis/Tika —
#  DEPLOYMENT.md, "Several installations on one server"; --help for every flag.)
exec "$(dirname "${BASH_SOURCE[0]}")/tools/deploy/configure.sh" "$@"
