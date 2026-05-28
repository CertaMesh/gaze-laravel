#!/usr/bin/env bash
# ■ DETERMINISTIC — Final performance validation
set -euo pipefail

echo "■ FINAL PERFORMANCE VALIDATION"

SETUP=".otat/benchmark_setup.md"
BASELINE_FILE=".otat/baseline_ms.txt"
BASELINE=$(cat "$BASELINE_FILE")

BENCH_CMD=$(sed -n '/```/,/```/{//!p}' "$SETUP" | head -1)
if [[ -z "$BENCH_CMD" ]]; then
  BENCH_CMD=$(grep -iE '^command:' "$SETUP" | head -1 | sed 's/[Cc]ommand:\s*//')
fi

echo "  Running 5 iterations for final measurement..."
RESULTS=""
for i in 1 2 3 4 5; do
  START=$(date +%s%N)
  eval "$BENCH_CMD" > /dev/null 2>&1
  END=$(date +%s%N)
  ELAPSED=$(( (END - START) / 1000000 ))
  echo "  Run $i: ${ELAPSED}ms"
  RESULTS="$RESULTS $ELAPSED"
done

AVG=$(echo $RESULTS | tr ' ' '\n' | awk '{sum+=$1} END {printf "%.0f", sum/NR}')
IMPROVEMENT=$(( (BASELINE - AVG) * 100 / BASELINE ))

echo ""
echo "  ╔══════════════════════════════╗"
echo "  ║  BEFORE:  ${BASELINE}ms"
echo "  ║  AFTER:   ${AVG}ms"
echo "  ║  GAIN:    ${IMPROVEMENT}%"
echo "  ╚══════════════════════════════╝"

echo "" >> .otat/benchmark_log.txt
echo "FINAL: ${AVG}ms (${IMPROVEMENT}% improvement from ${BASELINE}ms baseline)" >> .otat/benchmark_log.txt
