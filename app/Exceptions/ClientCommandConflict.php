<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class ClientCommandConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The client_command_id was already used for a different incident command.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'client_command_id_reused'], 409);
    }
}
