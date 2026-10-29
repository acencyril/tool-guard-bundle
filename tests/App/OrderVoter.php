<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests\App;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** alice's order is A-1; nobody else's is hers. */
final class OrderVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return 'ORDER_VIEW' === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return 'alice' === $token->getUserIdentifier() && 'A-1' === $subject;
    }
}
