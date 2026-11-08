# Changelog

Versions are git tags (`v0.1.0`, `v0.2.0`), published on Packagist.

## 0.3.0 — 2026-11-08

- **Stop button.** `tool-guard:stop` / `tool-guard:resume`, and on the approvals page "Stop the agents"
  (recommended), one agent, one tool, or "Stop everything" (emergency). Checked before any rule, on
  every path, across every PHP process (`stops_file`, or your own `StopStore`). A stopped call never
  runs; it is journaled and dispatches `ToolCallRefused` ("all agents stopped by boss at …: why"). A
  held call in a stopped scope stays held and cannot be approved until the resume.
- **Stopping the AI does not stop the business.** `Invocation::HUMAN`: a call made by a person or the
  app's own code through `GuardedInvoker` keeps every attribute rule and the journal, is not bound by
  the agents' roles, and is only halted by "Stop everything".
- The stop state in `tool-guard:approvals`, `debug:tool-guard` and the profiler panel.
- `ToolGuardAssertions`: `stopToolGuard()`, `resumeToolGuard()`, `assertToolStopped()`.
- `docs/recommendations.md`: what the AI Act (art. 14), the ANSSI's recommendations for generative AI
  and the OWASP Top 10 for Agentic Applications recommend, the feature that helps implement each, and
  what Tool Guard does not cover.
- The demo shows a stop.

## 0.2.0 — 2026-11-01

- Exactly-once approvals: a request is claimed atomically before the tool runs; two approvers clicking at once run it once.
- Requests expire (`expire_after`, default 24 h) and `four_eyes`: nobody approves a call made for themselves.
- Observe mode (`mode: observe`): nothing is blocked, the journal records what would have been.
- Tool Guard panel in the Symfony profiler.
- `debug:tool-guard` lists every tool and its rules; `--fail-on-unguarded` for CI.
- `#[RateLimit]` counts with Symfony RateLimiter, under a lock when the app has one.
- Agents have their own role (`tool_guard.agents`): a call runs only if the user and the agent are allowed.
- `GuardedInvoker`: the same rules for any other execution layer; toolbox workflows are guarded at every step.
- Notifier emails carry a "Review the request" button; approvals page with a light/dark theme switch.

## 0.1.0 — 2026-10-31

First release: `#[Granted]`, `#[Limit]`, `#[RateLimit]`, `#[RequiresApproval]` on Symfony AI agent
tools and MCP tools; the approvals page, the console and ntfy; the hash-chained journal;
`ToolGuardAssertions`; the one-command demo.
