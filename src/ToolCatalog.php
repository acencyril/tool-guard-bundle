<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle;

use Acencyril\ToolGuardBundle\DependencyInjection\GuardPass;

/** @internal The tools an AI can call in the app (#[AsTool], #[McpTool]), read from the classes GuardPass found. */
final class ToolCatalog
{
    /**
     * @param list<class-string> $classes
     *
     * @return list<array{string, list<string>, class-string, string}> name, channels, class, method
     */
    public static function tools(array $classes): array
    {
        $found = [];
        foreach ($classes as $class) {
            $r = new \ReflectionClass($class);
            foreach ($r->getAttributes(GuardPass::AS_TOOL) as $a) {
                $t = $a->newInstance();
                $found[$class.'::'.$t->method]['names'][] = $t->name;
                $found[$class.'::'.$t->method]['channels']['agent'] = true;
            }
            foreach ($r->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                foreach ($m->getAttributes(GuardPass::MCP_TOOL) as $a) {
                    $found[$class.'::'.$m->getName()]['names'][] = $a->newInstance()->name ?? $m->getName();
                    $found[$class.'::'.$m->getName()]['channels']['mcp'] = true;
                }
            }
        }
        $out = [];
        foreach ($found as $key => $t) {
            [$class, $method] = explode('::', $key, 2);
            $out[] = [implode(' / ', array_unique($t['names'])), array_keys($t['channels']), $class, $method];
        }
        usort($out, static fn ($a, $b) => [$a[2], $a[0]] <=> [$b[2], $b[0]]);

        return $out;
    }

    /**
     * Every tool name, sorted (the approvals page's stop buttons).
     *
     * @param list<class-string> $classes
     *
     * @return list<string>
     */
    public static function names(array $classes): array
    {
        $names = [];
        foreach (self::tools($classes) as [$name]) {
            array_push($names, ...explode(' / ', $name));
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
