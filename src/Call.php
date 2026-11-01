<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle;

/** @internal Runs a tool method with arguments given by name, as the approver and the invoker do. */
final class Call
{
    /** @param array<string, mixed> $arguments */
    public static function invoke(object $service, string $method, array $arguments): mixed
    {
        $m = new \ReflectionMethod($service, $method);
        $args = [];
        foreach ($m->getParameters() as $p) {
            if (\array_key_exists($p->getName(), $arguments)) {
                $args[] = $arguments[$p->getName()];
            } elseif ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
            } elseif ($p->allowsNull()) {
                $args[] = null;
            } else {
                throw new \RuntimeException(sprintf('Argument "%s" missing.', $p->getName()));
            }
        }

        return $m->invokeArgs($service, $args);
    }
}
