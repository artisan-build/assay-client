<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayClient\ParentLink;
use ArtisanBuild\AssayContracts\ReplayInputOmission;

/** @internal Source-agnostic state for one live invocation. */
final class InvocationState
{
    /** @var array<string, int> */
    private array $attempts = [];

    /** @var array<string, ParentLink|null> */
    private array $parents = [];

    /** @var array<string, array<string, ReplayInputOmission>> */
    private array $replayInputOmissions = [];

    /** @var array<string, array{attempt: int, parent: ParentLink|null}> */
    private array $approvalSnapshots = [];

    public function begin(string $invocationId, ?ParentLink $parent): int
    {
        unset($this->approvalSnapshots[$invocationId]);
        $this->parents[$invocationId] = $parent;

        return $this->attempts[$invocationId] = ($this->attempts[$invocationId] ?? 0) + 1;
    }

    /** @param list<ReplayInputOmission> $omissions */
    public function addReplayInputOmissions(string $invocationId, array $omissions): void
    {
        foreach ($omissions as $omission) {
            $this->replayInputOmissions[$invocationId][$omission->value] = $omission;
        }
    }

    /** @return list<ReplayInputOmission>|null */
    public function replayInputOmissions(string $invocationId): ?array
    {
        $observed = $this->replayInputOmissions[$invocationId] ?? [];
        $omissions = array_values(array_filter(
            ReplayInputOmission::cases(),
            static fn (ReplayInputOmission $omission): bool => isset($observed[$omission->value]),
        ));

        return $omissions === [] ? null : $omissions;
    }

    public function attempt(string $invocationId): int
    {
        return $this->attempts[$invocationId] ?? 1;
    }

    public function parent(string $invocationId): ?ParentLink
    {
        return $this->parents[$invocationId] ?? null;
    }

    public function finish(string $invocationId, bool $retainApprovalSnapshot = false): void
    {
        if ($retainApprovalSnapshot && isset($this->attempts[$invocationId])) {
            $this->approvalSnapshots[$invocationId] = [
                'attempt' => $this->attempts[$invocationId],
                'parent' => $this->parents[$invocationId] ?? null,
            ];
        } else {
            unset($this->approvalSnapshots[$invocationId]);
        }

        unset($this->attempts[$invocationId], $this->parents[$invocationId], $this->replayInputOmissions[$invocationId]);
    }

    /** @return array{attempt: int, parent: ParentLink|null}|null */
    public function approvalSnapshot(string $invocationId): ?array
    {
        return $this->approvalSnapshots[$invocationId] ?? null;
    }

    public function finishApproval(string $invocationId): void
    {
        unset($this->approvalSnapshots[$invocationId]);
    }
}
