#!/usr/bin/env bash
# TYDAL — load smoke for the public vault boundary. Fires concurrent requests
# at the read endpoints (meta, resources, search keyword+semantic) and reports
# throughput, error rate, and latency percentiles. Pure curl + bash, no
# external load tools. This is an on-demand ops check, NOT a CI gate — the
# CI-able perf guard is the query-budget regression suite
# (VaultIndexQueryBudgetTest). Requires a running stack + a seeded vault.
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: loadtest.sh <org/vault> [-n total] [-c concurrency] [-u base-url]

Load-smoke the vault boundary for one published (or key-bearing) vault.

  <org/vault>   e.g. tydal/expo-test  (seed one with seed-vault.sh)
  -n  total requests per endpoint      (default 200)
  -c  concurrency                      (default 20)
  -u  web root                         (default http://localhost:8000)
  -k  vault key (tvk_…) for private vaults
  -h  this help

Reports per endpoint: requests, errors (non-2xx), req/s, and p50/p95/p99/max ms.
EOF
}

VAULT=""; N=200; C=20; BASE="http://localhost:8000"; KEY=""
while [ $# -gt 0 ]; do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    -n) N="$2"; shift 2 ;;
    -c) C="$2"; shift 2 ;;
    -u) BASE="${2%/}"; shift 2 ;;
    -k) KEY="$2"; shift 2 ;;
    -*) echo "Unknown flag: $1" >&2; usage; exit 1 ;;
    *) VAULT="$1"; shift ;;
  esac
done
[ -n "$VAULT" ] || { usage; exit 1; }
command -v curl >/dev/null || { echo "curl required." >&2; exit 1; }

HDR=(); [ -n "$KEY" ] && HDR=(-H "X-Vault-Key: $KEY")
V="$BASE/v/$VAULT"

# One endpoint: fire N requests, C at a time; collect http_code + time_total.
run_endpoint() {
  local label="$1" url="$2" tmp
  tmp="$(mktemp)"
  local i=0
  while [ "$i" -lt "$N" ]; do
    local batch=0
    while [ "$batch" -lt "$C" ] && [ "$i" -lt "$N" ]; do
      # bash 3.2 (macOS) errors on "${HDR[@]}" for an empty array under set -u
      curl -s -o /dev/null ${HDR[@]+"${HDR[@]}"} -w '%{http_code} %{time_total}\n' "$url" >>"$tmp" &
      batch=$((batch + 1)); i=$((i + 1))
    done
    wait
  done

  # Aggregate with awk: error rate + latency percentiles (ms). LC_ALL=C so a
  # comma-decimal locale doesn't parse curl's "0.002" as 0 (or print "0,0").
  LC_ALL=C awk -v label="$label" '
    { code=$1; t=$2*1000; n++; sum+=t; times[n]=t; if (code < 200 || code >= 300) err++ }
    END {
      if (n==0) { printf "%-22s no samples\n", label; exit }
      for (i=1;i<=n;i++) for (j=i+1;j<=n;j++) if (times[j]<times[i]) { x=times[i]; times[i]=times[j]; times[j]=x }
      p50=times[int(n*0.50)+((n*0.50)==int(n*0.50)?0:1)]
      p95=times[int(n*0.95)+((n*0.95)==int(n*0.95)?0:1)]
      p99=times[int(n*0.99)+((n*0.99)==int(n*0.99)?0:1)]
      wall=(sum/1000)/'"$C"'; rps=(wall>0)?n/wall:0
      printf "%-22s n=%-5d err=%-4d rps=%-7.1f p50=%.1f p95=%.1f p99=%.1f max=%.1f ms\n", \
        label, n, err+0, rps, p50, p95, p99, times[n]
    }' "$tmp"
  rm -f "$tmp"
}

echo "Load smoke: $V  (n=$N/endpoint, c=$C)"
echo "--------------------------------------------------------------------------"
run_endpoint "meta"            "$V/meta"
run_endpoint "resources"       "$V/resources?per_page=50"
run_endpoint "search-keyword"  "$V/search?q=a"
run_endpoint "search-semantic" "$V/search?q=a&mode=semantic"
echo "--------------------------------------------------------------------------"
echo "Note: rps is approximate (wall-clock / concurrency). Compare runs, not absolutes."
