# Implement Final Optimization

Read `.otat/decision.md` to know which optimizations to keep and which to discard.

1. **Remove experimental code** from discarded hypotheses. Check git diff to ensure no leftover debug code, temporary benchmarks, or half-applied changes remain.

2. **Clean up winning optimizations** for production:
   - Remove any debug logging or timing instrumentation added during profiling
   - Add appropriate comments explaining WHY the optimization exists (not what it does)
   - Ensure code follows the project's existing style and conventions
   - Handle edge cases the benchmark may not have covered

3. **Run the project's test suite** to confirm nothing is broken. Fix any failures.

4. If tests pass, the implementation is ready for final benchmarking in the next step.
