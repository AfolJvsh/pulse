<?php

namespace Tests\Unit;

use App\Domain\Incidents\IncidentStateMachine;
use App\Domain\Incidents\IncidentStatus;
use App\Domain\Incidents\SequenceDecision;
use App\Domain\Incidents\SequenceGuard;
use DomainException;
use PHPUnit\Framework\TestCase;

final class IncidentDomainTest extends TestCase
{
    public function test_state_machine_allows_forward_incident_lifecycle(): void
    {
        $states = new IncidentStateMachine();
        $states->assert(IncidentStatus::Open, IncidentStatus::Investigating);
        $states->assert(IncidentStatus::Investigating, IncidentStatus::Mitigated);
        $states->assert(IncidentStatus::Mitigated, IncidentStatus::Resolved);
        $states->assert(IncidentStatus::Resolved, IncidentStatus::Closed);
        self::assertTrue(true);
    }

    public function test_state_machine_rejects_invalid_jump(): void
    {
        $this->expectException(DomainException::class);
        (new IncidentStateMachine())->assert(IncidentStatus::Open, IncidentStatus::Closed);
    }

    public function test_sequence_guard_distinguishes_duplicate_next_and_gap(): void
    {
        $guard = new SequenceGuard();
        self::assertSame(SequenceDecision::Ignore, $guard->decide(10, 10));
        self::assertSame(SequenceDecision::Apply, $guard->decide(10, 11));
        self::assertSame(SequenceDecision::Gap, $guard->decide(10, 13));
    }
}
