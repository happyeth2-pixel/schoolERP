<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * A temporary password for an administrator to hand over, and the one-time hand-over itself.
 *
 * The password is random (about 55 bits), easy to read out loud (no 0/O, 1/l/I), and only ever exists in
 * plain text between being made and being shown once to the administrator. It is stored hashed like any other
 * password and the account is marked "must change password". It never goes into the audit log.
 */
class TemporaryPassword
{
    private const LOWER = 'abcdefghjkmnpqrstuvwxyz';
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const DIGITS = '23456789';

    public static function make(): string
    {
        $pick = fn (string $set) => $set[random_int(0, strlen($set) - 1)];
        $chars = [$pick(self::UPPER), $pick(self::LOWER), $pick(self::DIGITS), $pick(self::DIGITS)];
        $all = self::LOWER.self::UPPER.self::DIGITS;
        while (count($chars) < 10) {
            $chars[] = $pick($all);
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {   // shuffle with a secure source
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /** Puts the sign-in details in the next page's flash, encrypted so the password is not readable in the session store. */
    public static function handOver(\App\Models\User $user, string $password, array $extra = []): void
    {
        session()->flash('credentials', $extra + [
            'name' => $user->name,
            'phone' => $user->login_phone ? PhoneNumber::display($user->login_phone) : null,
            'email' => $user->login_phone ? null : $user->email,
            'role' => $user->getRoleNames()->first(),
            'title' => $user->title,
            'branch' => $user->branch?->name,
            'password' => Crypt::encryptString($password),
        ]);
    }

    /** @return array<string, mixed>|null */
    public static function readHandOver(?array $flash): ?array
    {
        if (! $flash) {
            return null;
        }

        try {
            $flash['password'] = Crypt::decryptString($flash['password']);
        } catch (DecryptException) {
            return null;
        }

        return $flash;
    }
}
