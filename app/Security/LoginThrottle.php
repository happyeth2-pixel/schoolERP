<?php

namespace App\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Layered protection for signing in. Everything is decided on the server, from the request alone, and every number
 * comes from config/login_security.php. See AUTHENTICATION.md.
 *
 *  - ACCOUNT  failures against one phone number / email (from anywhere) earn short, growing cool-downs and, at the
 *             extreme, a temporary lock. Unknown numbers are tracked exactly like real ones, so nothing tells an
 *             attacker which accounts exist.
 *  - DEVICE   a browser that has signed in as that person before (a signed, encrypted cookie) is judged on its own
 *             record. Someone hammering at an account cannot lock its owner out of the owner's own device.
 *  - IP       failures from one network address are watched, never locked. When an address is clearly attacking
 *             (many failures, or many different accounts failing) only browsers that are NOT known to the account are
 *             slowed to one try every few seconds, so a whole school behind one address is never shut out.
 *  - BURST    a hard cap on how fast one number can be tried from one address.
 *
 * While any wait is running the password is not even checked, so guessing during a cool-down is pointless.
 */
class LoginThrottle
{
    /** The throttle key of whatever was typed: p: for a canonical mobile number, e: for an email, x: for anything else. */
    public static function keyFor(string $identifier): string
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            return 'e:'.mb_strtolower($identifier);
        }

        $phone = PhoneNumber::normalizeMobile($identifier);

        return $phone !== null ? 'p:'.$phone : 'x:'.sha1(mb_strtolower($identifier));
    }

    // ------------------------------------------------------------------ before the password is checked

    /** Seconds this attempt must wait before it is even considered (0 = go ahead). */
    public function wait(Request $request, string $key, ?int $userId): int
    {
        $now = $this->now();
        $wait = 0;

        $burst = config('login_security.burst');
        $burstKey = 'loginsec:burst:'.sha1($key.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($burstKey, $burst['attempts'])) {
            $wait = max($wait, RateLimiter::availableIn($burstKey));
        }

        $device = $this->device($request, $userId);
        $state = $device ? $this->get($this->deviceKey($userId, $device['id'])) : $this->get($this->accountKey($key));
        foreach (['locked_until', 'until'] as $field) {
            $wait = max($wait, ($state[$field] ?? 0) - $now);
        }

        if (! $device) {
            $ip = $this->get($this->ipKey($request->ip()));
            if (($ip['pressure_until'] ?? 0) > $now) {
                $gap = ($ip['failures'] ?? 0) >= config('login_security.ip.hard_ceiling') ? config('login_security.ip.hard_delay') : config('login_security.ip.pressure_delay');
                $wait = max($wait, ($ip['last'] ?? 0) + $gap - $now);
            }
        }

        return max(0, (int) $wait);
    }

    /** Records that an attempt is being made (feeds the burst cap). */
    public function attempting(Request $request, string $key): void
    {
        $burst = config('login_security.burst');
        RateLimiter::hit('loginsec:burst:'.sha1($key.'|'.$request->ip()), $burst['per_seconds']);
    }

    /** Written once per blocked stretch, not per blocked attempt, so an attack cannot flood the table. */
    public function blocked(Request $request, string $key, ?User $user, string $identifier, int $wait): void
    {
        $marker = 'loginsec:blocked:'.sha1($key.'|'.$request->ip());
        if (Cache::add($marker, 1, 60)) {
            SecurityEvent::log('login_blocked', $user, $identifier, null, ['wait_seconds' => $wait]);
        }
    }

    // ------------------------------------------------------------------ after the password is checked

    public function failed(Request $request, string $key, ?User $user, string $identifier): void
    {
        $now = $this->now();
        $cfg = config('login_security');
        $device = $this->device($request, $user?->id);
        $event = 'login_failed';
        $context = [];

        $account = $this->update($this->accountKey($key), function (array $st) use ($now, $cfg, &$event, &$context) {
            $st = $this->decay($st, $now, $cfg['account']['forget_after_minutes']);
            $st['failures'] = ($st['failures'] ?? 0) + 1;
            $st['last'] = $now;
            $context = ['failures' => $st['failures']];
            $this->schedule($st, $now, $cfg['account'], $event);

            return $st;
        });

        if ($device) {
            $this->update($this->deviceKey($user->id, $device['id']), function (array $st) use ($now, $cfg) {
                $st = $this->decay($st, $now, $cfg['account']['forget_after_minutes']);
                $st['failures'] = ($st['failures'] ?? 0) + 1;
                $st['last'] = $now;
                $ignored = '';
                $this->schedule($st, $now, $cfg['account'], $ignored);

                return $st;
            });
        }

        SecurityEvent::log('login_failed', $user, $identifier, null, $context + ['known_device' => (bool) $device]);
        if ($event !== 'login_failed') {
            SecurityEvent::log($event, $user, $identifier, null, $context + ['wait_seconds' => max(0, ($account['until'] ?? 0) - $now), 'lock_minutes' => $event === 'account_locked' ? $cfg['account']['lock_minutes'] : null]);
        }

        $this->watchAddress($request, $key, $now, $cfg['ip']);
    }

    public function succeeded(Request $request, string $key, User $user, string $identifier): void
    {
        $before = $this->get($this->accountKey($key));
        $failures = ($before['failures'] ?? 0);
        if ($failures > 0 && ($before['last'] ?? 0) >= $this->now() - config('login_security.account.forget_after_minutes') * 60) {
            SecurityEvent::log('login_succeeded_after_failures', $user, $identifier, null, ['failures' => $failures]);
        }

        Cache::forget($this->accountKey($key));
        if ($device = $this->device($request, $user->id)) {
            Cache::forget($this->deviceKey($user->id, $device['id']));
        }

        $this->rememberDevice($request, $user);
    }

    // ------------------------------------------------------------------ administrators

    /** Forgets every failure, cool-down and lock for this person (all their identifiers and all their devices). */
    public function clear(User $user): void
    {
        foreach (array_filter([$user->login_phone ? 'p:'.$user->login_phone : null, $user->email ? 'e:'.mb_strtolower($user->email) : null]) as $key) {
            Cache::forget($this->accountKey($key));
        }

        Cache::forever($this->epochKey($user->id), $this->epoch($user->id) + 1);
    }

    /** @return array{locked: bool, waiting: bool, seconds: int} how the account looks to someone who is not on a known device */
    public function status(User $user): array
    {
        $now = $this->now();
        $seconds = 0;
        $locked = false;

        foreach (array_filter([$user->login_phone ? 'p:'.$user->login_phone : null, $user->email ? 'e:'.mb_strtolower($user->email) : null]) as $key) {
            $st = $this->get($this->accountKey($key));
            $lockLeft = ($st['locked_until'] ?? 0) - $now;
            $locked = $locked || $lockLeft > 0;
            $seconds = max($seconds, $lockLeft, ($st['until'] ?? 0) - $now);
        }

        return ['locked' => $locked, 'waiting' => $seconds > 0, 'seconds' => max(0, (int) $seconds)];
    }

    // ------------------------------------------------------------------ pieces

    /** Puts the next wait (and, at the extreme, a lock) on this record. */
    private function schedule(array &$st, int $now, array $cfg, string &$event): void
    {
        // the wait follows the last of the free tries: with 3 free tries, the 3rd failure starts the first wait
        $over = $st['failures'] - $cfg['free_attempts'] + 1;
        if ($over <= 0) {
            return;
        }

        $steps = $cfg['cooldowns'];
        $st['until'] = $now + $steps[min($over, count($steps)) - 1];
        $event = $over === 1 ? 'login_cooldown' : $event;

        if ($st['failures'] >= $cfg['lock_after']) {
            $st['locked_until'] = $now + $cfg['lock_minutes'] * 60;
            // after the lock the next failures start at the longest cool-down again, until a quiet spell
            $st['failures'] = $cfg['free_attempts'] + count($steps);
            $event = 'account_locked';
        }
    }

    /** Failures older than the quiet spell are forgotten; so are expired locks and waits. */
    private function decay(array $st, int $now, int $forgetMinutes): array
    {
        if (($st['last'] ?? 0) < $now - $forgetMinutes * 60) {
            return [];
        }

        return $st;
    }

    private function watchAddress(Request $request, string $key, int $now, array $cfg): void
    {
        $ip = (string) $request->ip();
        $cutoff = $now - $cfg['window_minutes'] * 60;
        $alert = null;

        $this->update($this->ipKey($ip), function (array $st) use ($now, $cutoff, $cfg, $key, &$alert) {
            $events = array_values(array_filter($st['events'] ?? [], fn ($e) => $e[0] >= $cutoff));
            $events[] = [$now, substr(sha1($key), 0, 12)];
            $events = array_slice($events, -1000);

            $st['events'] = $events;
            $st['failures'] = count($events);
            $st['distinct'] = count(array_unique(array_column($events, 1)));
            $st['last'] = $now;

            $attacking = $st['failures'] >= $cfg['failure_alert'] || $st['distinct'] >= $cfg['distinct_alert'];
            if ($attacking && ($st['pressure_until'] ?? 0) <= $now) {
                $alert = ['failures' => $st['failures'], 'accounts' => $st['distinct']];
                $st['pressure_until'] = $now + $cfg['pressure_minutes'] * 60;
            }

            return $st;
        });

        if ($alert) {
            SecurityEvent::log('ip_pressure', null, null, null, $alert);
        }
    }

    /** @return array{id: string, users: list<int>}|null the browser's known-device record, when it is known to THIS account */
    private function device(Request $request, ?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $raw = $request->cookie(config('login_security.device.cookie'));
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($data) || ! isset($data['d'], $data['u']) || ! is_array($data['u']) || ! in_array($userId, $data['u'], true)) {
            return null;
        }

        return ['id' => (string) $data['d'], 'users' => $data['u']];
    }

    private function rememberDevice(Request $request, User $user): void
    {
        $cfg = config('login_security.device');
        $raw = $request->cookie($cfg['cookie']);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $id = is_array($data) && isset($data['d']) ? (string) $data['d'] : Str::random(20);
        $users = is_array($data) && is_array($data['u'] ?? null) ? $data['u'] : [];
        $users = array_slice(array_values(array_unique([...$users, $user->id])), -$cfg['max_accounts']);

        Cookie::queue(Cookie::make($cfg['cookie'], json_encode(['d' => $id, 'u' => $users]), $cfg['days'] * 24 * 60, '/', null, $request->isSecure(), true, false, 'lax'));
    }

    // ------------------------------------------------------------------ storage

    private function now(): int
    {
        return now()->getTimestamp();
    }

    private function accountKey(string $key): string
    {
        return 'loginsec:acct:'.sha1($key);
    }

    private function deviceKey(int $userId, string $deviceId): string
    {
        return 'loginsec:dev:'.$userId.':'.$this->epoch($userId).':'.sha1($deviceId);
    }

    private function ipKey(string $ip): string
    {
        return 'loginsec:ip:'.sha1($ip);
    }

    private function epochKey(int $userId): string
    {
        return 'loginsec:epoch:'.$userId;
    }

    private function epoch(int $userId): int
    {
        return (int) Cache::get($this->epochKey($userId), 0);
    }

    private function get(string $key): array
    {
        return (array) Cache::get($key, []);
    }

    /** Read-modify-write under a short lock so two simultaneous failures are both counted. */
    private function update(string $key, callable $change): array
    {
        $apply = function () use ($key, $change) {
            $new = $change($this->get($key));
            Cache::put($key, $new, now()->addDay());

            return $new;
        };

        try {
            return Cache::lock('lock:'.$key, 5)->block(3, $apply);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return $apply();
        }
    }
}
