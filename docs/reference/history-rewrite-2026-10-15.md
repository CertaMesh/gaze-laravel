# History rewrite of 2026-10-15

On 2026-10-15 the git history of this repository was rewritten to replace an author identity that does not belong in a public repository. Code, tags and release notes are otherwise unchanged: every tag below points at a commit with the same tree as before.

What changed:

- **Commit SHAs.** Every commit got a new SHA.
- **Commit signatures.** Signatures on rewritten commits were dropped. They would no longer be valid, and re-signing other people's commits would misstate who signed them.
- **Tags `v0.6.4` to `v0.11.1`** keep their names and point at the rewritten commits.
- **Tags `v0.12.0` to `v0.16.0`** belonged to immutable GitHub releases, so they could not be moved. They were deleted and re-published as `v0.12.1` to `v0.16.1` with identical code. Where the changelog or `UPGRADING.md` mentions `v0.12.0` to `v0.16.0`, the matching `.1` tag carries the same code.

## Tag mapping

| Old tag | Old commit | New tag | New commit |
|---|---|---|---|
| `v0.6.4` | `de71234d0540` | `v0.6.4` | `cbe20527bbd3` |
| `v0.6.5` | `c5638f5c2ee3` | `v0.6.5` | `ae36c7ef4b13` |
| `v0.6.6` | `643208afa8d5` | `v0.6.6` | `b68cb5495325` |
| `v0.7.0` | `f95c2db52a25` | `v0.7.0` | `1ba27b6d2fa0` |
| `v0.8.0` | `45f9b09314ba` | `v0.8.0` | `bcf01c2098f1` |
| `v0.8.1` | `4edf0c08b45a` | `v0.8.1` | `d07b1ef40d61` |
| `v0.9.0` | `87dedb1e64d4` | `v0.9.0` | `b1b9665c52d4` |
| `v0.9.1` | `323f3a83677c` | `v0.9.1` | `780087c60128` |
| `v0.10.0` | `8ce09a3a8146` | `v0.10.0` | `4a7ec1f282d1` |
| `v0.11.0` | `6150502c8db0` | `v0.11.0` | `f21ca418df47` |
| `v0.11.1` | `a6ac9548bf8d` | `v0.11.1` | `17ba87c05618` |
| `v0.12.0` | `a3d42e8fd214` | `v0.12.1` | `b8dae8af24c3` |
| `v0.13.0` | `58919c429456` | `v0.13.1` | `25b8b8fec6d3` |
| `v0.14.0` | `2681250d29fb` | `v0.14.1` | `b470642a7d82` |
| `v0.15.0` | `ff008f9f378c` | `v0.15.1` | `647bd3294dce` |
| `v0.16.0` | `4cc0731089d4` | `v0.16.1` | `d71156bd4e61` |
| `main` (2026-10-15) | `4cc0731089d4` | `main` | `d71156bd4e61` |

## Updating a clone

```sh
git fetch --prune --prune-tags --force origin
git checkout main && git reset --hard origin/main
```

Delete or re-create local branches that were based on the old history. Pushing them would bring the old commits back.

Projects that pin `certamesh/gaze-laravel` in `composer.lock` should run `composer update certamesh/gaze-laravel`. A pin on `v0.12.0` to `v0.16.0` moves to the matching `.1` release.
