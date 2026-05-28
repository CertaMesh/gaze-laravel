[LOOP]

# Test Next Hypothesis

Read `.otat/hypotheses.md`. Find the first hypothesis with **Status: UNTESTED**.

If no untested hypotheses remain, output `OTAT_DONE` and stop.

Otherwise:

1. Note the current git state so the change can be isolated.
2. Implement the optimization described in the hypothesis.
3. Run the benchmark command from `.otat/benchmark_setup.md`.
4. Record the result by updating the hypothesis entry in `.otat/hypotheses.md`:
   - Change `**Status**: UNTESTED` to `**Status**: TESTED`
   - Add a result line: `- **Result**: [TESTED: Xms -> Yms (Z% improvement)]`
5. If the hypothesis made things worse or had no effect, revert the change.
6. If the hypothesis improved performance, keep the change in place.

Test exactly ONE hypothesis per invocation.
