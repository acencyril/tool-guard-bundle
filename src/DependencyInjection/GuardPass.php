<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\DependencyInjection;

use Acencyril\ToolGuardBundle\Approval\Approver;
use Acencyril\ToolGuardBundle\Attribute\Granted;
use Acencyril\ToolGuardBundle\Attribute\Limit;
use Acencyril\ToolGuardBundle\Attribute\RateLimit;
use Acencyril\ToolGuardBundle\Attribute\RequiresApproval;
use Acencyril\ToolGuardBundle\CurrentUser;
use Acencyril\ToolGuardBundle\Guard;
use Acencyril\ToolGuardBundle\GuardedInvoker;
use Acencyril\ToolGuardBundle\Integration\Mcp\GuardedReferenceHandler;
use Mcp\Capability\Registry\ReferenceHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1. The approver gets a locator of every service whose class carries a guard attribute, keyed
 *    by class, to run an approved call later.
 * 2. Each MCP server of symfony/mcp-bundle gets its reference handler wrapped by the guard.
 */
final class GuardPass implements CompilerPassInterface
{
    private const ATTRIBUTES = [Granted::class, Limit::class, RateLimit::class, RequiresApproval::class];
    public const AS_TOOL = 'Symfony\\AI\\Agent\\Toolbox\\Attribute\\AsTool';
    public const MCP_TOOL = 'Mcp\\Capability\\Attribute\\McpTool';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(Guard::class)) {
            return;
        }
        $tools = [];
        $all = [];
        foreach ($container->getDefinitions() as $id => $def) {
            $class = $container->getParameterBag()->resolveValue($def->getClass() ?? $id);
            if (!\is_string($class) || $def->isAbstract() || $def->isSynthetic() || self::skipped($class)) {
                continue;
            }
            if (self::isGuarded($class)) {
                $tools[$class] = new Reference($id);
                $all[$class] = true;
            } elseif (self::isTool($class)) {
                $all[$class] = true;
            }
        }
        $locator = ServiceLocatorTagPass::register($container, $tools);
        $container->getDefinition(Approver::class)->setArgument('$tools', $locator);
        $container->hasDefinition(GuardedInvoker::class) && $container->getDefinition(GuardedInvoker::class)->setArgument('$tools', $locator);
        $container->setParameter('tool_guard.tool_classes', array_keys($all));

        if (!class_exists(ReferenceHandler::class)) {
            return;
        }
        foreach ($container->getDefinitions() as $id => $def) {
            if (1 !== preg_match('/^mcp\.server\.([^.]+)\.builder$/', $id, $m)) {
                continue;
            }
            $inner = null;
            $locator = null;
            $calls = [];
            foreach ($def->getMethodCalls() as [$method, $args]) {
                if ('setReferenceHandler' === $method) {
                    $inner = $args[0];
                    continue;
                }
                if ('setContainer' === $method) {
                    $locator = $args[0];
                }
                $calls[] = [$method, $args];
            }
            $inner ??= new Definition(ReferenceHandler::class, null === $locator ? [] : [$locator]);
            $guarded = 'tool_guard.mcp.'.$m[1].'.reference_handler';
            $container->register($guarded, GuardedReferenceHandler::class)
                ->setArguments([$inner, new Reference(Guard::class), new Reference(CurrentUser::class)]);
            $calls[] = ['setReferenceHandler', [new Reference($guarded)]];
            $def->setMethodCalls($calls);
        }
    }

    /** Framework and vendor services carry no tool of the application: not even loaded. */
    private static function skipped(string $class): bool
    {
        if (str_starts_with($class, 'Acencyril\\ToolGuardBundle\\') && !str_contains($class, '\\Tests\\')) {
            return true;
        }
        foreach (['Symfony\\', 'Doctrine\\', 'Twig\\', 'Psr\\', 'Mcp\\', 'Monolog\\'] as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isGuarded(string $class): bool
    {
        try {
            // a class whose parent is not installed is not ours to load
            if (!class_exists($class)) {
                return false;
            }
            $r = new \ReflectionClass($class);
        } catch (\Throwable) {
            return false;
        }
        foreach (self::ATTRIBUTES as $a) {
            if ([] !== $r->getAttributes($a)) {
                return true;
            }
        }
        $guarded = false;
        foreach ($r->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            foreach (self::ATTRIBUTES as $a) {
                if ([] !== $m->getAttributes($a)) {
                    $guarded = true;
                    self::checkArguments($m);
                    break;
                }
            }
        }

        return $guarded;
    }

    /** A Symfony AI agent tool or an MCP tool, guarded or not (listed by debug:tool-guard). */
    private static function isTool(string $class): bool
    {
        try {
            if (!class_exists($class)) {
                return false;
            }
            $r = new \ReflectionClass($class);
        } catch (\Throwable) {
            return false;
        }
        if ([] !== $r->getAttributes(self::AS_TOOL)) {
            return true;
        }
        foreach ($r->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ([] !== $m->getAttributes(self::MCP_TOOL)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A rule naming an argument the method does not have would refuse every call, silently: a
     * typo fails the build instead ("amout" for "amount").
     */
    private static function checkArguments(\ReflectionMethod $m): void
    {
        $params = array_map(static fn (\ReflectionParameter $p) => $p->getName(), $m->getParameters());
        $named = [];
        foreach ($m->getAttributes(Limit::class) as $a) {
            $named[] = $a->newInstance()->argument;
        }
        foreach ($m->getAttributes(Granted::class) as $a) {
            $subject = $a->newInstance()->subject;
            null !== $subject && $named[] = $subject;
        }
        foreach ($named as $arg) {
            if (!\in_array($arg, $params, true)) {
                throw new \InvalidArgumentException(sprintf('Tool Guard: %s::%s() has a rule on argument "%s", which it does not take (%s).', $m->class, $m->getName(), $arg, implode(', ', $params) ?: 'no arguments'));
            }
        }
    }
}
