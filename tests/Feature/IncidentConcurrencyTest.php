<?php

namespace Tests\Feature;

use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

final class IncidentConcurrencyTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    public function test_stale_expected_version_returns_latest_snapshot_without_lost_update(): void
    {
        [$user, $organization] = $this->tenant('conflict');
        $this->actingAsTenant($user);

        $incident = $this->postJson('/api/incidents', [
            'organization_id' => $organization->id,
            'title' => 'Database latency',
            'severity' => 'sev2',
            'client_command_id' => (string) Str::uuid(),
        ])->assertCreated();
        $id = $incident->json('id');

        $this->putJson("/api/incidents/{$id}/severity", [
            'severity' => 'sev1', 'expected_version' => 1, 'client_command_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('version', 2);

        $this->putJson("/api/incidents/{$id}/status", [
            'status' => 'investigating', 'expected_version' => 1, 'client_command_id' => (string) Str::uuid(),
        ])->assertStatus(409)->assertJsonPath('latest.version', 2);

        self::assertSame('open', Incident::findOrFail($id)->status->value);
    }

    public function test_client_command_replay_is_idempotent(): void
    {
        [$user, $organization] = $this->tenant('replay'); $this->actingAsTenant($user);
        $id = $this->postJson('/api/incidents', ['organization_id'=>$organization->id,'title'=>'Cache incident','severity'=>'sev3'])->assertCreated()->json('id');
        $command = (string) Str::uuid();
        $payload = ['severity'=>'sev2','expected_version'=>1,'client_command_id'=>$command];

        $this->putJson("/api/incidents/{$id}/severity", $payload)->assertOk()->assertJsonPath('version', 2);
        $this->putJson("/api/incidents/{$id}/severity", $payload)->assertOk()->assertJsonPath('version', 2);
        self::assertSame(1, Incident::findOrFail($id)->events()->where('event_type','SeverityChanged')->count());
    }

    public function test_event_replay_returns_only_sequences_after_cursor(): void
    {
        [$user, $organization] = $this->tenant('events'); $this->actingAsTenant($user);
        $id = $this->postJson('/api/incidents', ['organization_id'=>$organization->id,'title'=>'Replay incident','severity'=>'sev3'])->assertCreated()->json('id');
        $this->postJson("/api/incidents/{$id}/comments", ['body'=>'first','client_command_id'=>(string) Str::uuid()])->assertCreated();
        $this->postJson("/api/incidents/{$id}/comments", ['body'=>'second','client_command_id'=>(string) Str::uuid()])->assertCreated();

        $this->getJson("/api/incidents/{$id}/events?after_sequence=1")
            ->assertOk()->assertJsonPath('mode','events')
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('events.0.sequence', 2);
    }

    public function test_cross_tenant_incident_access_is_forbidden(): void
    {
        [$user] = $this->tenant('a'); [, $other] = $this->tenant('b');
        $incident = Incident::create(['organization_id'=>$other->id,'incident_number'=>1,'title'=>'Private','severity'=>'sev4','status'=>'open','version'=>1,'last_sequence'=>0,'started_at'=>now()]);
        $this->actingAsTenant($user);
        $this->getJson('/api/incidents/'.$incident->id)->assertForbidden();
    }
}
