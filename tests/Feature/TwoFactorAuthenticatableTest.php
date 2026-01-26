<?php

declare(strict_types=1);

use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\UserTwoFactorAuth;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->user = User::factory()->create();
});

describe('TwoFactorAuthenticatable Trait', function () {
    describe('twoFactorAuth() relationship', function () {
        it('returns HasOne relationship', function () {
            expect($this->user->twoFactorAuth())->toBeInstanceOf(
                \Illuminate\Database\Eloquent\Relations\HasOne::class
            );
        });

        it('returns null when no 2FA record exists', function () {
            expect($this->user->twoFactorAuth)->toBeNull();
        });

        it('returns 2FA record when it exists', function () {
            $twoFactorAuth = UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => 'JBSWY3DPEHPK3PXP',
                'recovery_codes' => ['code1', 'code2'],
                'confirmed_at' => now(),
            ]);

            $this->user->refresh();

            expect($this->user->twoFactorAuth)->toBeInstanceOf(UserTwoFactorAuth::class)
                ->and($this->user->twoFactorAuth->id)->toBe($twoFactorAuth->id);
        });
    });

    describe('hasTwoFactorAuthEnabled()', function () {
        it('returns false when no 2FA record exists', function () {
            expect($this->user->hasTwoFactorAuthEnabled())->toBeFalse();
        });

        it('returns false when 2FA record exists but secret_key is null', function () {
            UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => null,
                'recovery_codes' => [],
                'confirmed_at' => now(),
            ]);

            $this->user->refresh();

            expect($this->user->hasTwoFactorAuthEnabled())->toBeFalse();
        });

        it('returns false when 2FA record exists but confirmed_at is null', function () {
            UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => 'JBSWY3DPEHPK3PXP',
                'recovery_codes' => [],
                'confirmed_at' => null,
            ]);

            $this->user->refresh();

            expect($this->user->hasTwoFactorAuthEnabled())->toBeFalse();
        });

        it('returns true when 2FA is fully enabled', function () {
            UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => 'JBSWY3DPEHPK3PXP',
                'recovery_codes' => ['code1', 'code2'],
                'confirmed_at' => now(),
            ]);

            $this->user->refresh();

            expect($this->user->hasTwoFactorAuthEnabled())->toBeTrue();
        });
    });

    describe('twoFactorAuthSecretKey()', function () {
        it('returns null when no 2FA record exists', function () {
            expect($this->user->twoFactorAuthSecretKey())->toBeNull();
        });

        it('returns the secret key when 2FA record exists', function () {
            $secretKey = 'JBSWY3DPEHPK3PXP';

            UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => $secretKey,
                'recovery_codes' => [],
            ]);

            $this->user->refresh();

            expect($this->user->twoFactorAuthSecretKey())->toBe($secretKey);
        });
    });

    describe('twoFactorRecoveryCodes()', function () {
        it('returns empty array when no 2FA record exists', function () {
            expect($this->user->twoFactorRecoveryCodes())->toBe([]);
        });

        it('returns empty array when recovery_codes is null', function () {
            UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => 'JBSWY3DPEHPK3PXP',
                'recovery_codes' => null,
            ]);

            $this->user->refresh();

            expect($this->user->twoFactorRecoveryCodes())->toBe([]);
        });

        it('returns recovery codes as array', function () {
            $codes = ['CODE1-CODE1', 'CODE2-CODE2', 'CODE3-CODE3'];

            UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => 'JBSWY3DPEHPK3PXP',
                'recovery_codes' => $codes,
            ]);

            $this->user->refresh();

            expect($this->user->twoFactorRecoveryCodes())->toBe($codes);
        });
    });

    describe('twoFactorReplaceRecoveryCode()', function () {
        it('does nothing when no 2FA record exists', function () {
            // Should not throw
            $this->user->twoFactorReplaceRecoveryCode('nonexistent');

            expect($this->user->twoFactorAuth)->toBeNull();
        });

        it('does nothing when code is not found in recovery codes', function () {
            $codes = ['CODE1-CODE1', 'CODE2-CODE2', 'CODE3-CODE3'];

            UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => 'JBSWY3DPEHPK3PXP',
                'recovery_codes' => $codes,
            ]);

            $this->user->refresh();

            $this->user->twoFactorReplaceRecoveryCode('NONEXISTENT');

            $this->user->refresh();

            expect($this->user->twoFactorRecoveryCodes())->toBe($codes);
        });

        it('replaces a used recovery code with a new one', function () {
            $codes = ['CODE1-CODE1', 'CODE2-CODE2', 'CODE3-CODE3'];

            UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => 'JBSWY3DPEHPK3PXP',
                'recovery_codes' => $codes,
            ]);

            $this->user->refresh();

            $this->user->twoFactorReplaceRecoveryCode('CODE2-CODE2');

            $this->user->refresh();
            $newCodes = $this->user->twoFactorRecoveryCodes();

            // Should still have 3 codes
            expect($newCodes)->toHaveCount(3)
                // First and third codes should be unchanged
                ->and($newCodes[0])->toBe('CODE1-CODE1')
                ->and($newCodes[2])->toBe('CODE3-CODE3')
                // Second code should be different and in the expected format
                ->and($newCodes[1])->not->toBe('CODE2-CODE2')
                ->and($newCodes[1])->toMatch('/^[A-F0-9]{10}-[A-F0-9]{10}$/');
        });
    });

    describe('twoFactorQrCodeUrl()', function () {
        it('generates valid TOTP URL', function () {
            $secretKey = 'JBSWY3DPEHPK3PXP';

            UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => $secretKey,
                'recovery_codes' => [],
            ]);

            $this->user->refresh();

            $url = $this->user->twoFactorQrCodeUrl();

            expect($url)->toStartWith('otpauth://totp/')
                ->and($url)->toContain($secretKey)
                ->and($url)->toContain(rawurlencode($this->user->email))
                ->and($url)->toContain('issuer=');
        });

        it('includes app name in the URL', function () {
            $appName = config('app.name');

            UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => 'JBSWY3DPEHPK3PXP',
                'recovery_codes' => [],
            ]);

            $this->user->refresh();

            $url = $this->user->twoFactorQrCodeUrl();

            expect($url)->toContain(rawurlencode($appName));
        });
    });

    describe('twoFactorQrCodeSvg()', function () {
        it('returns empty string when no secret exists', function () {
            expect($this->user->twoFactorQrCodeSvg())->toBe('');
        });

        it('returns SVG content when secret exists', function () {
            UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => 'JBSWY3DPEHPK3PXP',
                'recovery_codes' => [],
            ]);

            $this->user->refresh();

            $svg = $this->user->twoFactorQrCodeSvg();

            expect($svg)->toStartWith('<svg')
                ->and($svg)->toContain('</svg>');
        });
    });

    describe('generateRecoveryCode() via twoFactorReplaceRecoveryCode()', function () {
        it('generates codes in the expected format', function () {
            $codes = ['TESTCODE1'];

            UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => 'JBSWY3DPEHPK3PXP',
                'recovery_codes' => $codes,
            ]);

            $this->user->refresh();

            $this->user->twoFactorReplaceRecoveryCode('TESTCODE1');

            $this->user->refresh();
            $newCode = $this->user->twoFactorRecoveryCodes()[0];

            // Format: 10 uppercase hex chars - 10 uppercase hex chars
            expect($newCode)->toMatch('/^[A-F0-9]{10}-[A-F0-9]{10}$/');
        });

        it('generates unique codes', function () {
            $codes = ['CODE1', 'CODE2', 'CODE3'];

            UserTwoFactorAuth::create([
                'user_id' => $this->user->id,
                'secret_key' => 'JBSWY3DPEHPK3PXP',
                'recovery_codes' => $codes,
            ]);

            $this->user->refresh();

            // Replace all codes
            $this->user->twoFactorReplaceRecoveryCode('CODE1');
            $this->user->refresh();
            $this->user->twoFactorReplaceRecoveryCode('CODE2');
            $this->user->refresh();
            $this->user->twoFactorReplaceRecoveryCode('CODE3');
            $this->user->refresh();

            $newCodes = $this->user->twoFactorRecoveryCodes();

            // All codes should be unique
            expect(array_unique($newCodes))->toHaveCount(3);
        });
    });
});

describe('UserTwoFactorAuth Model', function () {
    it('belongs to a user', function () {
        $twoFactorAuth = UserTwoFactorAuth::create([
            'user_id' => $this->user->id,
            'secret_key' => 'JBSWY3DPEHPK3PXP',
            'recovery_codes' => [],
        ]);

        expect($twoFactorAuth->user)->toBeInstanceOf(User::class)
            ->and($twoFactorAuth->user->id)->toBe($this->user->id);
    });

    it('casts recovery_codes to collection', function () {
        $codes = ['CODE1', 'CODE2'];

        $twoFactorAuth = UserTwoFactorAuth::create([
            'user_id' => $this->user->id,
            'secret_key' => 'JBSWY3DPEHPK3PXP',
            'recovery_codes' => $codes,
        ]);

        expect($twoFactorAuth->recovery_codes)->toBeInstanceOf(\Illuminate\Support\Collection::class)
            ->and($twoFactorAuth->recovery_codes->toArray())->toBe($codes);
    });

    it('casts confirmed_at to datetime', function () {
        $confirmedAt = now();

        $twoFactorAuth = UserTwoFactorAuth::create([
            'user_id' => $this->user->id,
            'secret_key' => 'JBSWY3DPEHPK3PXP',
            'recovery_codes' => [],
            'confirmed_at' => $confirmedAt,
        ]);

        expect($twoFactorAuth->confirmed_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });
});
