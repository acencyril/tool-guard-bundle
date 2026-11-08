# Security policy

Tool Guard is a security component: a way around a rule is a vulnerability, even if no data leaks.

## Reporting a vulnerability

Please report it privately, through GitHub: **Security → Report a vulnerability** on this repository.
Do not open a public issue for it.

Useful in a report: the attribute or integration involved (Symfony AI agent tool or MCP tool), the
call that should have been refused or held, and what happened instead.

You get an answer within 72 hours. A confirmed vulnerability is fixed within 7 days, and the fix is
released before any public detail; you are credited in the advisory unless you prefer not to be.
This is best effort: Tool Guard has a single maintainer, and this policy says so rather than promise
more.

## Supported versions

| Version | Supported |
|---------|-----------|
| 0.3.x   | ✅        |
| < 0.3   | ❌        |

Tool Guard runs on PHP 8.2 to 8.4 and Symfony 7.3, 7.4 and 8.x. Every PHP version is tested on each
push, with `composer audit` on the dependencies (see `.github/workflows/tests.yml`).

## Versions

Tool Guard follows [SemVer](https://semver.org/). While it is in 0.x, a minor version (0.3 → 0.4) may
change behaviour or configuration: every such change is in `CHANGELOG.md`. A patch version
(0.3.0 → 0.3.1) never does.
