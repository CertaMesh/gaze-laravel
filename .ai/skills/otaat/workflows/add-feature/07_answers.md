[LOOP]
# Step 07 — Answer Questions Empirically

Read `.otat/questions.md`.

Pick ONE unanswered question and answer it with evidence.

## What to do

1. **Find the first unanswered question** in `.otat/questions.md` (any question not marked `[ANSWERED]`).

2. **Answer it empirically** — run code, search the repo, check documentation, test behavior. Do not guess or speculate.

3. **Write the answer** directly under the question in `.otat/questions.md`. Include:
   - The evidence (command output, file contents, test results)
   - The conclusion (one clear sentence)
   - Mark the question as `[ANSWERED]`

4. **If all questions are answered**, output `OTAT_DONE`.

## Rules

- Answer exactly ONE question per loop iteration.
- Every answer must include evidence — a command you ran, a file you read, output you observed.
- Do not answer with opinions. Only facts and observations.
- Do not modify the design or implementation. Only gather information.
- Write answers directly into `.otat/questions.md`, preserving the existing content.

## Loop exit

Output `OTAT_DONE` when no unanswered questions remain.
