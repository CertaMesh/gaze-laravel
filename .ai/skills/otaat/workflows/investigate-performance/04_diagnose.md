# Diagnose Root Causes

Read `.otat/profile.md` to understand where time is being spent.

For each hotspot identified in the profile, explain WHY it is slow. Focus on root causes:

- Algorithmic complexity (O(n^2) loops, unnecessary sorting, brute-force search)
- N+1 query patterns (loading relations one at a time)
- Missing indexes (full table scans)
- Cache misses (recomputing expensive results)
- Blocking I/O (synchronous HTTP calls, file reads in hot paths)
- Unnecessary work (serializing unused data, loading unneeded columns)
- Memory pressure (large object graphs, excessive allocations triggering GC)
- Contention (lock waits, connection pool exhaustion)

Write `.otat/diagnosis.md` with a section per root cause. Each section must include:

1. **What** is happening (the symptom from the profile)
2. **Why** it is slow (the underlying reason)
3. **Evidence** (specific numbers from the profile that support this)

Do NOT suggest fixes or optimizations. This step is diagnosis only. Describe the disease, not the cure.
