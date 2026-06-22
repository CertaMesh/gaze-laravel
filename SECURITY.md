# Security Policy

## Supported Versions

| Version | Supported |
| ------- | --------- |
| v0.6.x | Yes |
| Older | No |

## Reporting a Vulnerability

Report vulnerabilities privately through GitHub Security Advisories:

https://github.com/CertaMesh/gaze-laravel/security/advisories/new

This opens a private channel between the reporter and maintainers. If GitHub
Advisories is not suitable, email security@certamesh.com instead.

Do not report vulnerabilities in public issues or pull requests. Public
vulnerability reports will be removed, and reporters will be asked to use the
private advisory channel.

## Disclosure Timeline

We target 90 days from report to fix.

We coordinate disclosure timing with the reporter before publishing details.

## Scope

In scope:

- `src/`
- The binary install plugin
- Encrypted blob handling
- Audit database writes

Out of scope:

- The upstream `CertaMesh/gaze` Rust binary itself. Report those issues to
  https://github.com/CertaMesh/gaze.

## Acknowledgments

Security reporters are credited in `CHANGELOG.md` unless they request
anonymity.
