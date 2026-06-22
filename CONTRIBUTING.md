# Contributing

Thanks for helping improve `gaze-laravel`.

This package is the Laravel adapter for the Gaze pseudonymization engine. Keep
changes small, tested, and easy to review.

## Quick Start for Contributors

Clone the repository:

```bash
git clone https://github.com/CertaMesh/gaze-laravel.git
cd gaze-laravel
```

Install PHP dependencies:

```bash
composer install
```

Run the test suite:

```bash
composer test
```

Run static analysis:

```bash
composer analyse
```

Check formatting with Pint:

```bash
composer format -- --test
```

Apply formatting when needed:

```bash
composer format
```

## Branch and Commit Conventions

`main` is the default branch.

Create feature branches from `main`:

```bash
git switch main
git pull --ff-only
git switch -c feature/<topic>
```

Use imperative commit subjects:

```text
docs: clarify audit DB setup
fix: reject invalid encrypted blob payloads
test: cover binary resolver fallback
```

Commit subject rules:

- Keep the subject at 72 characters or less.
- Say what changed, not what happened.
- Reference issues or pull requests when useful.
- Keep unrelated edits out of the commit.

The `[agent]` commit prefix is reserved for AI-driven work. See `AGENTS.md` for
agent-specific rules.

## Pull Requests

One logical change per pull request.

Small PRs are preferred. They are easier to review, test, and revert.

PR descriptions should include:

- What changed.
- Why it changed.
- Test plan with exact commands run.
- Related issues, pull requests, or follow-up work.

Pull requests are squash-merged to `main`.

## Testing

New behavior needs Pest coverage.

Keep tests close to the behavior they protect:

- Unit tests cover small PHP classes and value objects.
- Feature tests cover Laravel service provider, facade, and command behavior.
- Integration tests live in `tests/Integration/`.

Integration tests that exercise the real Gaze binary require `GAZE_BINARY`:

```bash
GAZE_BINARY=/path/to/gaze composer test
```

Live NER artifact smoke tests are opt-in and require the documented environment
variables.

See `docs/testing.md` for the full testing workflow.

## Documentation

Prose documentation lives in `docs/*.md`.

The README is promo-only. Keep detailed setup, behavior, and troubleshooting
notes in `docs/`.

When adding or changing environment variables, update
`docs/configuration.md`.

When behavior changes affect users, update the relevant doc page in the same
PR.

## Reporting Bugs

Use the bug report issue template:

https://github.com/CertaMesh/gaze-laravel/issues/new/choose

Include:

- PHP version.
- Laravel version.
- `gaze-laravel` version.
- Operating system.
- Gaze binary version.
- Reproduction steps.
- Logs or stack traces when available.

## Security Reports

Do not report vulnerabilities in public issues or pull requests.

Use the private reporting process in `SECURITY.md`.

## Code of Conduct

All contributors are expected to follow `CODE_OF_CONDUCT.md`.

## License

This project is licensed under Apache-2.0. See `LICENSE`.
