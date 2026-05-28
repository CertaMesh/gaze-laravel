# Step 06 — Surface Design Questions

Read `.otat/design.md` and `.otat/patterns.md`.

Write questions that, once answered, would make every design decision obvious.

## What to do

1. **Identify uncertainties** in the design. Where are there multiple valid approaches? Where does the design assume something that hasn't been verified?

2. **Write questions** that can be answered empirically — by reading code, running commands, checking docs, or testing behavior. Each question should:
   - Target a specific design decision
   - Be answerable with evidence (not opinion)
   - Include context on why the answer matters

3. **Format each question** as:
   ```
   ### Q: [The question]
   **Why it matters:** [One sentence on what this unblocks]
   **How to answer:** [Specific command, file to read, or test to run]
   ```

## Rules

- DO NOT answer the questions. Only write them.
- Every question must be empirically answerable (no "should we..." or "what's the best...").
- Focus on questions that would change the implementation approach if answered differently.
- Aim for 3-8 questions. More than 8 suggests the design is too uncertain.

## Output

Write the result to `.otat/questions.md`.
