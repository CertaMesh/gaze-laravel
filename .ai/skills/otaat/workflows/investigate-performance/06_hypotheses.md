[LOOP]

# Generate Optimization Hypothesis

Read `.otat/diagnosis.md` to understand the root causes.
Read `.otat/hypotheses.md` (create it if it does not exist) to see what hypotheses have already been listed.

Add ONE new optimization hypothesis that is not already in the file. Write it as a new section appended to `.otat/hypotheses.md` in this format:

## Hypothesis: <Name>

- **What it changes**: <specific code change described in one sentence>
- **Expected impact**: <estimated % improvement and reasoning>
- **Risk level**: Low / Medium / High
- **Status**: UNTESTED

Each hypothesis must be grounded in the diagnosis. Do not invent problems that were not identified in the profiling/diagnosis steps.

If all reasonable hypotheses have already been covered (typically 3-5 for most issues), output `OTAT_DONE` and stop.
