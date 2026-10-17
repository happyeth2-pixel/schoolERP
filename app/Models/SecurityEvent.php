<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One security-relevant sign-in event. Append-only, never holds a password, identifiers are stored masked. */
class SecurityEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['school_id', 'user_id', 'actor_id', 'event', 'identifier', 'ip_address', 'user_agent', 'context', 'created_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** +251911223344 -> +2519*****344; a@b.com -> a***@b.com; anything else -> first character and stars. */
    public static function mask(?string $identifier): ?string
    {
        if ($identifier === null || trim($identifier) === '') {
            return null;
        }

        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            [$name, $domain] = explode('@', $identifier, 2);

            return mb_substr($name, 0, 1).'***@'.$domain;
        }

        $canonical = PhoneNumber::normalizeMobile($identifier);
        if ($canonical !== null) {
            return substr($canonical, 0, 5).'*****'.substr($canonical, -3);
        }

        return mb_substr($identifier, 0, 1).'***';
    }

    /**
     * @param  array<string, mixed>  $context  small, non-secret details (counts, seconds); never a password
     */
    public static function log(string $event, ?User $user = null, ?string $identifier = null, ?User $actor = null, array $context = []): void
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        static::create([
            'school_id' => $user?->school_id ?? $actor?->school_id,
            'user_id' => $user?->id,
            'actor_id' => $actor?->id,
            'event' => $event,
            'identifier' => static::mask($identifier),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 200) : null,
            'context' => $context === [] ? null : $context,
            'created_at' => now(),
        ]);
    }
}
