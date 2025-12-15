<?php

namespace App\Http\Controllers;

use App\Http\Services\VaultService;
use Illuminate\Http\Request;

class VaultController
{
    public function vault_getValid($clientId): ?string
    {
        return VaultService::getValid($clientId);
    }
    public function vault_put(Request $request): ?string
    {
        $data = json_decode($request->input('data'));

        $clientId = $data->clientId;
        $plainToken = $data->plainToken;

        return VaultService::put($clientId, $plainToken);
    }
}