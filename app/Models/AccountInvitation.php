<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Set your password" invitation for a new account.
 *
 *  - random 40-character token, only its SHA-256 hash is stored;
 *  - expires after VALID_FOR_HOURS and works exactly once;
 *  - issuing a new invitation revokes any earlier pending one;
 *  - any other password change (e.g. Forgot password) revokes pending ones;
 *  - separate from the normal password-reset flow, which is unchanged.
 */
class AccountInvitation extends Model
{
    public const VALID_FOR_HOURS = 72;

    public $timestamps = false;

    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'used_at', 'used_ip', 'revoked_at', 'invited_by', 'created_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{url: string, expires_at: \Illuminate\Support\Carbon} */
    public static function issueFor(User $user, ?User $by = null): array
    {
        return DB::transaction(function () use ($user, $by) {
            static::revokePending($user->id);

            $token = Str::random(40);
            $expires = now()->addHours(self::VALID_FOR_HOURS);

            static::create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expires,
                'invited_by' => $by?->id,
                'created_at' => now(),
            ]);

            return ['url' => route('invitation.show', $token), 'expires_at' => $expires];
        });
    }

    public static function revokePending(int $userId): void
    {
        static::where('user_id', $userId)->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** The invitation for this token if it can still be used (unused, not revoked, not expired, account active). */
    public static function findUsable(string $token): ?self
    {
        $invitation = static::where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->with('user.school')
            ->first();

        return $invitation && $invitation->user && $invitation->user->status === 'active' ? $invitation : null;
    }

    /**
     * Sets the password and uses the invitation up, atomically: two people
     * opening the same link at once cannot both succeed.
     */
    public function accept(string $password, string $ip): ?User
    {
        return DB::transaction(function () use ($password, $ip) {
            $fresh = static::whereKey($this->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->used_at || $fresh->revoked_at || $fresh->expires_at->isPast()) {
                return null;
            }

            $fresh->update(['used_at' => now(), 'used_ip' => $ip]);

            $user = $fresh->user;
            $user->forceFill(['password' => $password, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

            return $user;
        });
    }

    /**
     * 'pending' | 'expired' | 'active' per user id. 'active' = has set a password
     * (or predates invitations); 'pending' = a usable link is out; 'expired' = the
     * last link lapsed or was revoked without the password ever being set.
     */
    public static function statesFor(iterable $userIds): array
    {
        $latest = static::whereIn('user_id', $userIds)->orderBy('id')->get()->groupBy('user_id')->map->last();

        $states = [];
        foreach ($userIds as $id) {
            $inv = $latest->get($id);
            $states[$id] = match (true) {
                $inv === null, $inv->used_at !== null => 'active',
                $inv->revoked_at === null && $inv->expires_at->isFuture() => 'pending',
                default => 'expired',
            };
        }

        return $states;
    }
}
