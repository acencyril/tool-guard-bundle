<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Approval;

/** Where held calls wait. The bundle ships a file store; implement this for Doctrine, Redis… */
interface ApprovalStore
{
    public function save(Approval $approval): void;

    public function get(string $id): ?Approval;

    /** @return list<Approval> newest first */
    public function all(?string $status = null, int $limit = 100): array;

    /**
     * Reserves a request for one decision, atomically: true for the first caller, false for every
     * other one. Two approvers clicking at once (or one double click) must not run a refund twice.
     */
    public function claim(string $id): bool;
}
