# Decide on Winning Optimizations

Read `.otat/hypotheses.md` to see all tested hypotheses and their benchmark results.
Read `.otat/benchmark_log.txt` to see the full benchmark history.

Analyze the results and write `.otat/decision.md` with:

## Winning Optimizations

List each optimization to KEEP. For each:
- Name (from hypotheses)
- Measured improvement
- Why it is safe to ship

## Discarded Optimizations

List each optimization to DISCARD. For each:
- Name
- Why it is being discarded (no improvement, too risky, marginal gain not worth complexity)

## Expected Combined Result

- Baseline: Xms
- Expected after all winners applied: Yms
- Expected improvement: Z%

Note: if optimizations interact (e.g., two caching layers), the combined improvement may not be the sum of individual improvements. Account for this.
