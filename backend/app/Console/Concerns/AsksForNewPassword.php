<?php

namespace App\Console\Concerns;

use App\Models\User;

/**
 * The password a NEW account gets from a command: the given option value,
 * else asked (hidden, typed twice) when interactive, else null — the caller
 * generates one. An existing account keeps its own, so null is returned for
 * it. False when the choice is invalid: already reported, before anything is
 * created. Shared by `user:create` and `exhibitions:create --curator`.
 */
trait AsksForNewPassword
{
    /** Same floor as account registration (AuthController). */
    public const MIN_PASSWORD_LENGTH = 8;

    /** @param  string  $who  how the account is named in messages ("user", "curator") */
    private function newPassword(string $email, ?string $given, string $option, string $who): string|false|null
    {
        if (User::where('email', $email)->exists()) {
            if ($given !== null) {
                $this->warn("{$email} already has a TYDAL account — {$option} ignored; their password is left unchanged.");
            }

            return null;
        }

        if ($given === null && $this->input->isInteractive()) {
            $given = (string) $this->secret("Password for the new {$who} {$email} (leave empty to generate one)");
            if ($given === '') {
                return null;
            }
            if ($given !== (string) $this->secret('Repeat the password')) {
                $this->error('The passwords do not match — nothing was created.');

                return false;
            }
        }

        if ($given !== null && strlen($given) < self::MIN_PASSWORD_LENGTH) {
            $this->error("The {$who} password must be at least ".self::MIN_PASSWORD_LENGTH.' characters — nothing was created.');

            return false;
        }

        return $given;
    }
}
