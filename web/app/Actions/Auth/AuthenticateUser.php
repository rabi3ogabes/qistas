<?php

namespace App\Actions\Auth;

use App\Auth\SignInLog;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Throwable;

/**
 * Checks sign-in credentials for Fortify (the website) and for the phone app's API. Wrong email and wrong password are
 * indistinguishable (same message, same work done). A suspension is only revealed to someone who proved the password.
 *
 * A wrong password for an account that exists also goes into that person's sign-in history (SignInLog::failed), which
 * writes it after the answer is sent, so it is no work done before the answer. This is the one place every password
 * check passes through, once per attempt that fails, on either channel. A success is not recorded here: Fortify checks
 * a good password twice per sign-in, and the sign-in may still need its code.
 */
final class AuthenticateUser
{
    /** @var array<string, string> the decoy this process already has, per hashing set-up (see decoyHash) */
    private static array $decoyHashes = [];

    public function __construct(private readonly SignInLog $signIns) {}

    /** @param  'web'|'app'  $channel  where the attempt comes from: Fortify calls this with the request alone (the website) */
    public function __invoke(Request $request, string $channel = 'web'): ?User
    {
        $user = User::where('email', mb_strtolower(trim((string) $request->input(Fortify::username()))))->first();

        // The same work whether the email exists or not: the decoy is fetched either way, and exactly one hash is checked.
        $decoy = $this->decoyHash();
        $matches = Hash::check((string) $request->input('password'), $user->password ?? $decoy);

        if ($user === null || ! $matches) {
            Audit::record('login.failed', userId: $user?->id);

            // An unknown e-mail has no history to add to, and what was typed is never kept.
            if ($user !== null) {
                $this->signIns->failed($user, $request, channel: $channel);
            }

            return null;
        }

        if ($user->isSuspended()) {
            Audit::record('login.blocked', userId: $user->id);

            throw ValidationException::withMessages([
                Fortify::username() => __('Your account has been suspended. Please contact support.'),
            ]);
        }

        return $user;
    }

    /**
     * The hash an unknown email's password is checked against, so that it costs one hash check like a known one. It is
     * made once and kept in the cache, not per request: a process that lives for one request (PHP-FPM, FrankenPHP's
     * classic mode) would otherwise make a hash for every unknown email, a second slow step a known one never takes.
     * The key names the hashing driver and its cost, so a changed cost makes a new decoy that costs what a real
     * password hash costs. It is a hash of random text, no secret. When the cache cannot keep it, one is made on the
     * spot: slower for that process, never a refused sign-in.
     */
    private function decoyHash(): string
    {
        $key = 'auth.decoy-hash:'.self::hashing();

        return self::$decoyHashes[$key] ??= self::rememberedDecoy($key);
    }

    private static function rememberedDecoy(string $key): string
    {
        try {
            $hash = Cache::rememberForever($key, fn (): string => Hash::make(Str::random(40)));

            // An entry that is no password hash (damaged, or written by something else) would make every check throw.
            if (Hash::isHashed($hash)) {
                return $hash;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return Hash::make(Str::random(40));
    }

    /** The configured hashing driver and its cost, e.g. "bcrypt:12" or "argon2id:65536-1-4". */
    private static function hashing(): string
    {
        $driver = (string) config('hashing.driver');

        $cost = in_array($driver, ['argon', 'argon2id'], true)
            ? implode('-', array_map(fn (string $setting): int => (int) config("hashing.argon.{$setting}"), ['memory', 'threads', 'time']))
            : (string) (int) config('hashing.bcrypt.rounds');

        return "{$driver}:{$cost}";
    }
}
