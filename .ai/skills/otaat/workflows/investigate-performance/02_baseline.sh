#!/usr/bin/env bash
# ■ DETERMINISTIC — Capture performance baseline
set -euo pipefail

echo "■ CAPTURING PERFORMANCE BASELINE"

SETUP=".otat/benchmark_setup.md"
if [[ ! -f "$SETUP" ]]; then
  echo "ERROR: $SETUP not found. Step 01 must run first."
  exit 1
fi

# Extract benchmark command (look for code block or "Command:" line)
BENCH_CMD=$(sed -n '/```/,/```/{//!p}' "$SETUP" | head -1)
if [[ -z "$BENCH_CMD" ]]; then
  BENCH_CMD=$(grep -iE '^command:' "$SETUP" | head -1 | sed 's/[Cc]ommand:\s*//')
fi

if [[ -z "$BENCH_CMD" ]]; then
  echo "ERROR: Could not extract benchmark command from benchmark_setup.md"
  echo "  Include a code block with the command or a 'Command: ...' line"
  exit 1
fi

echo "  Benchmark: $BENCH_CMD"
echo "  Running 3 iterations..."
echo ""

RESULTS=""
for i in 1 2 3; do
  START=$(date +%s%N)
  eval "$BENCH_CMD" > /dev/null 2>&1
  END=$(date +%s%N)
  ELAPSED=$(( (END - START) / 1000000 ))
  echo "  Run $i: ${ELAPSED}ms"
  RESULTS="$RESULTS $ELAPSED"
done

# Calculate average
AVG=$(echo $RESULTS | tr ' ' '\n' | awk '{sum+=$1} END {printf "%.0f", sum/NR}')

echo ""
echo "■ BASELINE: ${AVG}ms average"
echo "$AVG" > .otat/baseline_ms.txt
echo "Baseline: ${AVG}ms ($(date -u))" > .otat/benchmark_log.txt
echo "Command: $BENCH_CMD" >> .otat/benchmark_log.txt
