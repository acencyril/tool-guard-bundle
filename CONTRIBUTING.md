# Contributing

Thanks for helping. Issues and pull requests are welcome here.

## The one rule

Tool Guard **fails closed**. When a rule cannot be checked (no user, no security, an argument missing,
not a number), the call is refused or held, never run. A change that lets a call through because
something could not be checked is a bug, even if every test passes.

A way around a rule is a security issue: report it privately, as [SECURITY.md](SECURITY.md) explains,
not in a public issue.

## Running it

PHP 8.2+ and Composer:

```bash
composer install
vendor/bin/phpunit     # the whole suite, including the one-command demo (demo/run.sh) over real HTTP
sh demo/run.sh         # the demo on its own: both shops, attacked by the scripted agent
```

No model and no API key are needed. The real-model demo (`demo:attack-real-model`) needs `HF_TOKEN`
or `OPENROUTER_API_KEY`; its test uses a stand-in model, so the suite runs without one.

The CI runs the suite on PHP 8.2, 8.3 and 8.4.

## What a change needs

- **A test that fails before the change and passes after.** For a rule, test it the way an attack
  would come: `ToolGuardAssertions` calls the tool over the real MCP server or a Symfony AI toolbox
  (see `tests/Demo/DemoRulesTest.php` for the usual tricks: the argument left out, text for a number,
  infinity, someone else's order).
- **The same behaviour on both paths.** An attribute guards Symfony AI agent tools and MCP tools alike;
  a change to one path is tested on the other.
- **The README updated** if what a user sees changes (an attribute, a message, a command).

## Upstream bugs

Symfony AI and the PHP MCP SDK are pre-releases. When something breaks there, report it upstream with
a reproduction ([symfony/ai](https://github.com/symfony/ai/issues),
[php-sdk](https://github.com/modelcontextprotocol/php-sdk/issues)), keep any workaround small and
inside `demo/`, and link the upstream issue from it, as `demo/src/ToolParametersNormalizer.php` does.

## Code style

Follow the surrounding code: `declare(strict_types=1)`, `final` classes, constructor promotion,
`readonly` where it fits, typed everything. Comments say *why*, not what the code already says.
