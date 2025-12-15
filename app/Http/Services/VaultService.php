<?php

namespace App\Http\Services;

use App\Models\OauthToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

class VaultService
{
    public static function getValid($clientId): ?string
    {
        $row = OauthToken::where('client_id', $clientId)->latest('updated_at')->first();
        if (!$row) {
            return null;
        }

        if ($row->expires_at && Carbon::now()->lt($row->expires_at)) {
            return Crypt::decrypt($row->token);
        }

        return null;
    }

    public static function put(string $clientId, string $plainToken): OauthToken
    {
        $encrypted = Crypt::encrypt($plainToken);
        $expiresAt = Carbon::now()->addMinutes(210);

        return tap(
            OauthToken::updateOrCreate(
                ['client_id' => $clientId],
                ['token' => $encrypted, 'expires_at' => $expiresAt]
            )
        )->refresh();
    }

    public static function checkDropboxToken(){
        $dropbox = app(DropboxService::class);
        try {
            $token = $dropbox->ensureValidToken();
        } catch (Exception $e) {
            return response()->json(['code' => 500, 'error' => $e->getMessage()]);
        }
    }
}

