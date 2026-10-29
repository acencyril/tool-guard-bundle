<?php

declare(strict_types=1);

namespace ToolGuardDemo;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** An order belongs to the customer signed in: the same voter your back office already has. */
final class OrderVoter extends Voter
{
    public function __construct(private readonly Shop $shop)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return 'ORDER_OWNER' === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return \is_string($subject) && ($this->shop->data()['orders'][$subject]['customer'] ?? null) === $token->getUserIdentifier();
    }
}
