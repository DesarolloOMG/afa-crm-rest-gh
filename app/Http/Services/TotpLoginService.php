<?php

namespace App\Http\Services;

use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TotpLoginService
{
    const PERIOD = 30;
    const WINDOW = 1;
    const SETUP_TTL_SECONDS = 600;
    const MAX_FAILURES = 5;
    const LOCK_SECONDS = 300;
    const ISSUER = 'AFA Innovations';

    public function codeForTime(string $secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, self::PERIOD);
        $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $binaryCounter, $this->decodeBase32($secret), true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function begin(Usuario $user): array
    {
        return DB::transaction(function () use ($user) {
            Usuario::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $now = Carbon::now();
            $record = DB::table('usuario_totp')->where('usuario_id', $user->id)->first();

            if ($record && $record->enabled_at) {
                return ['state' => 'required'];
            }

            if ($record && $record->locked_until && Carbon::parse($record->locked_until)->greaterThan($now)) {
                return [
                    'state' => 'locked',
                    'retry_after' => max(1, Carbon::parse($record->locked_until)->timestamp - $now->timestamp),
                ];
            }

            if (!$record || !$record->pending_expires_at
                || Carbon::parse($record->pending_expires_at)->lessThanOrEqualTo($now)) {
                $secret = $this->encodeBase32(random_bytes(20));
                $values = [
                    'secret' => Crypt::encryptString($secret),
                    'pending_expires_at' => $now->copy()->addSeconds(self::SETUP_TTL_SECONDS),
                    'enabled_at' => null,
                    'last_used_step' => null,
                    'fail_count' => 0,
                    'locked_until' => null,
                    'updated_at' => $now,
                ];
                if ($record) {
                    DB::table('usuario_totp')->where('usuario_id', $user->id)->update($values);
                } else {
                    $values['usuario_id'] = $user->id;
                    $values['created_at'] = $now;
                    DB::table('usuario_totp')->insert($values);
                }
            } else {
                $secret = Crypt::decryptString($record->secret);
                $expiresIn = max(1, Carbon::parse($record->pending_expires_at)->timestamp - $now->timestamp);
            }

            return [
                'state' => 'setup',
                'otpauth_uri' => $this->provisioningUri($secret, $user->email),
                'expires_in' => isset($expiresIn) ? $expiresIn : self::SETUP_TTL_SECONDS,
            ];
        });
    }

    public function authorizationStatus(Usuario $user): array
    {
        $record = DB::table('usuario_totp')->where('usuario_id', $user->id)->first();
        if (!$record || !$record->enabled_at) {
            return [
                'ready' => false,
                'message' => 'El usuario debe iniciar sesión y configurar su aplicación autenticadora antes de autorizar esta operación.',
            ];
        }

        $now = Carbon::now();
        if ($record->locked_until && Carbon::parse($record->locked_until)->greaterThan($now)) {
            return [
                'ready' => false,
                'locked' => true,
                'retry_after' => max(1, Carbon::parse($record->locked_until)->timestamp - $now->timestamp),
                'message' => 'Demasiados intentos. Intenta de nuevo más tarde.',
            ];
        }

        return [
            'ready' => true,
            'message' => 'Abre la aplicación autenticadora del usuario e ingresa el código actual de seis dígitos.',
        ];
    }

    public function verify(Usuario $user, $code): array
    {
        return $this->verifyCode($user, $code, false);
    }

    public function verifyAuthorization(Usuario $user, $code): array
    {
        return $this->verifyCode($user, $code, true);
    }

    private function verifyCode(Usuario $user, $code, bool $requireEnabled): array
    {
        return DB::transaction(function () use ($user, $code, $requireEnabled) {
            Usuario::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $now = Carbon::now();
            $record = DB::table('usuario_totp')->where('usuario_id', $user->id)->first();

            if ($requireEnabled && (!$record || !$record->enabled_at)) {
                return [
                    'accepted' => false,
                    'message' => 'El usuario no ha configurado su aplicación autenticadora.',
                ];
            }

            if (!$record || (!$record->enabled_at && (!$record->pending_expires_at
                || Carbon::parse($record->pending_expires_at)->lessThanOrEqualTo($now)))) {
                return [
                    'accepted' => false,
                    'expired' => true,
                    'message' => 'La configuración del autenticador expiró.',
                ];
            }

            if ($record->locked_until && Carbon::parse($record->locked_until)->greaterThan($now)) {
                return [
                    'accepted' => false,
                    'locked' => true,
                    'retry_after' => max(1, Carbon::parse($record->locked_until)->timestamp - $now->timestamp),
                    'message' => 'Demasiados intentos. Intenta de nuevo más tarde.',
                ];
            }

            if (!is_string($code) || !preg_match('/^\d{6}$/D', $code)) {
                return $this->recordFailure($user->id, $record, $now, 'Código inválido.');
            }

            $secret = Crypt::decryptString($record->secret);
            $matchedStep = $this->matchingStep($secret, $code, $now->timestamp);
            if ($matchedStep === null || ($record->last_used_step !== null && $matchedStep <= (int)$record->last_used_step)) {
                return $this->recordFailure($user->id, $record, $now, 'Código inválido.');
            }

            $updated = DB::table('usuario_totp')
                ->where('usuario_id', $user->id)
                ->where(function ($query) use ($matchedStep) {
                    $query->whereNull('last_used_step')->orWhere('last_used_step', '<', $matchedStep);
                })
                ->update([
                    'enabled_at' => $record->enabled_at ?: $now,
                    'pending_expires_at' => null,
                    'last_used_step' => $matchedStep,
                    'fail_count' => 0,
                    'locked_until' => null,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                $fresh = DB::table('usuario_totp')->where('usuario_id', $user->id)->first();
                return $this->recordFailure($user->id, $fresh, $now, 'Código inválido.');
            }

            return ['accepted' => true];
        });
    }

    private function provisioningUri(string $secret, string $email): string
    {
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => self::ISSUER,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . rawurlencode(self::ISSUER . ':' . $email) . '?' . $query;
    }

    private function encodeBase32(string $value): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $encoded = '';

        foreach (unpack('C*', $value) as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= $alphabet[($buffer >> $bits) & 31];
                $buffer &= $bits === 0 ? 0 : (1 << $bits) - 1;
            }
        }

        if ($bits > 0) {
            $encoded .= $alphabet[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }

    private function decodeBase32(string $value): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $value = strtoupper(rtrim($value, '='));
        $buffer = 0;
        $bits = 0;
        $decoded = '';

        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $digit = strpos($alphabet, $value[$index]);
            if ($digit === false) {
                throw new \InvalidArgumentException('El secreto TOTP no tiene un formato Base32 válido.');
            }

            $buffer = ($buffer << 5) | $digit;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 255);
                $buffer &= $bits === 0 ? 0 : (1 << $bits) - 1;
            }
        }

        if ($decoded === '') {
            throw new \InvalidArgumentException('El secreto TOTP está vacío.');
        }

        return $decoded;
    }

    private function matchingStep(string $secret, string $code, int $timestamp)
    {
        $currentStep = intdiv($timestamp, self::PERIOD);
        foreach ([0, -1, 1] as $offset) {
            $step = $currentStep + $offset;
            if ($step >= 0 && hash_equals($this->codeForTime($secret, $step * self::PERIOD), $code)) {
                return $step;
            }
        }
        return null;
    }

    private function recordFailure(int $userId, $record, Carbon $now, string $message): array
    {
        $failCount = ($record->locked_until && Carbon::parse($record->locked_until)->lessThanOrEqualTo($now))
            ? 1 : (int)$record->fail_count + 1;
        $values = ['fail_count' => $failCount, 'updated_at' => $now];
        if ($record->locked_until && Carbon::parse($record->locked_until)->lessThanOrEqualTo($now)) {
            $values['locked_until'] = null;
        }
        if ($failCount >= self::MAX_FAILURES) {
            $values['locked_until'] = $now->copy()->addSeconds(self::LOCK_SECONDS);
        }
        DB::table('usuario_totp')->where('usuario_id', $userId)->update($values);
        return ['accepted' => false, 'message' => $message];
    }
}
