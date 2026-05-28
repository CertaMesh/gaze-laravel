#!/usr/bin/env bash
# ■ DETERMINISTIC — Benchmark and compare to baseline
set -euo pipefail

echo "■ BENCHMARK COMPARISON"

SETUP=".otat/benchmark_setup.md"
BASELINE_FILE=".otat/baseline_ms.txt"

if [[ ! -f "$BASELINE_FILE" ]]; then
  echo "ERROR: No baseline found. Run step 02 first."
  exit 1
fi

BASELINE=$(cat "$BASELINE_FILE")

BENCH_CMD=$(sed -n '/```/,/```/{//!p}' "$SETUP" | head -1)
if [[ -z "$BENCH_CMD" ]]; then
  BENCH_CMD=$(grep -iE '^command:' "$SETUP" | head -1 | sed 's/[Cc]ommand:\s*//')
fi

echo "  Running 3 iterations..."
RESULTS=""
for i in 1 2 3; do
  START=$(date +%s%N)
  eval "$BENCH_CMD" > /dev/null 2>&1
  END=$(date +%s%N)
  ELAPSED=$(( (END - START) / 1000000 ))
  echo "  Run $i: ${ELAPSED}ms"
  RESULTS="$RESULTS $ELAPSED"
done

AVG=$(echo $RESULTS | tr ' ' '\n' | awk '{sum+=$1} END {printf "%.0f", sum/NR}')

if [[ $BASELINE -gt 0 ]]; then
  IMPROVEMENT=$(( (BASELINE - AVG) * 100 / BASELINE ))
else
  IMPROVEMENT=0
fi

echo ""
echo "  Baseline:    ${BASELINE}ms"
echo "  Current:     ${AVG}ms"
echo "  Improvement: ${IMPROVEMENT}%"
echo ""

echo "After optimization: ${AVG}ms (${IMPROVEMENT}% improvement)" >> .otat/benchmark_log.txt

if [[ $AVG -lt $BASELINE ]]; then
  echo "■ BENCHMARK: IMPROVED ✓ (${IMPROVEMENT}% faster)"
elif [[ $AVG -gt $((BASELINE + BASELINE/10)) ]]; then
  echo "■ BENCHMARK: REGRESSION ✗ (${IMPROVEMENT}% slower!)"
  exit 1
else
  echo "■ BENCHMARK: NO SIGNIFICANT CHANGE"
fi
