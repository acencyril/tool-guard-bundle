<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** Who is behind a call: the signed-in user's identifier, if Symfony Security knows one. */
final class CurrentUser
{
    public function __construct(private readonly ?TokenStorageInterface $tokens = null)
    {
    }

    public function identifier(): ?string
    {
        return $this->tokens?->getToken()?->getUser()?->getUserIdentifier();
    }
}
