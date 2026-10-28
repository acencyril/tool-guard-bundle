<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests\Fixtures;

use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** "alice" owns order A-1 only. */
final class Auth implements AuthorizationCheckerInterface
{
    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
    {
        return 'ORDER_VIEW' === $attribute && 'A-1' === $subject;
    }
}
