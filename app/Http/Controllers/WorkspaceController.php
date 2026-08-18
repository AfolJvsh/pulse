<?php

namespace App\Http\Controllers;

use App\Models\{Organization, Team};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceController
{
    public function members(Request $request, string $organizationId): JsonResponse
    {
        $organization = $this->organization($request, $organizationId);

        return response()->json(
            $organization->users()
                ->select(['users.id', 'users.name', 'users.email'])
                ->orderBy('users.name')
                ->get()
        );
    }

    public function teams(Request $request, string $organizationId): JsonResponse
    {
        $organization = $this->organization($request, $organizationId);

        return response()->json(
            Team::where('organization_id', $organization->id)->orderBy('name')->get()
        );
    }

    public function storeTeam(Request $request, string $organizationId): JsonResponse
    {
        $organization = $this->organization($request, $organizationId);
        $data = $request->validate(['name' => 'required|string|max:120']);

        $team = Team::create([
            'organization_id' => $organization->id,
            'name' => trim($data['name']),
        ]);

        return response()->json($team, 201);
    }

    public function deleteTeam(Request $request, string $organizationId, Team $team): JsonResponse
    {
        $organization = $this->organization($request, $organizationId);
        abort_unless($team->organization_id === $organization->id, 404);
        $team->delete();

        return response()->json(['deleted' => true]);
    }

    private function organization(Request $request, string $organizationId): Organization
    {
        abort_unless($request->user()->organizations()->whereKey($organizationId)->exists(), 403);
        return Organization::findOrFail($organizationId);
    }
}
