<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

final class RealtimeTelemetryController
{
    public function heartbeat(Request $request, Incident $incident): JsonResponse
    {
        abort_unless($request->user()->organizations()->whereKey($incident->organization_id)->exists(), 403);
        $data = $request->validate([
            'session_id' => 'required|uuid',
            'reconnect' => 'sometimes|boolean',
        ]);

        $key = "pulse:active-sockets:{$incident->organization_id}";
        $member = $request->user()->id.':'.$data['session_id'];
        $expiresAt = time() + 45;
        Redis::zadd($key, $expiresAt, $member);
        Redis::expire($key, 90);
        Redis::zremrangebyscore($key, '-inf', (string) time());

        if (($data['reconnect'] ?? false) === true) {
            Redis::incr("pulse:metrics:reconnects:{$incident->organization_id}");
        }

        return response()->json(['ok' => true, 'expires_at' => $expiresAt]);
    }
}
