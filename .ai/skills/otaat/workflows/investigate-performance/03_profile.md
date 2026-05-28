# Profile the Slow Code Path

Read `.otat/benchmark_setup.md` to understand what operation is slow and how to trigger it.

Profile the slow code path using whatever profiling tools are available and appropriate for this stack. Examples:

- **PHP/Laravel**: Xdebug profiler, Clockwork, `DB::enableQueryLog()`, Laravel Telescope
- **Node.js**: `--prof`, `--inspect`, clinic.js, `console.time`
- **Python**: cProfile, py-spy, line_profiler
- **General**: `time`, `strace`, `perf`, flamegraphs
- **Database**: `EXPLAIN ANALYZE`, slow query log, query count logging
- **Frontend**: Chrome DevTools Performance tab, Lighthouse

Collect profiling data and write `.otat/profile.md` with:

## Top 5 Hotspots

For each hotspot:
- Where it is (file, function, line)
- How much time it consumes (absolute and percentage)
- Call count (how many times it runs)

## Memory (if relevant)

Peak memory usage, large allocations, growth patterns.

## Query Analysis (if database involved)

Total query count, slowest queries, duplicate/N+1 patterns.

## Raw Profiler Output

Include the raw output (or a summarized version if extremely large) so it can be referenced later.
