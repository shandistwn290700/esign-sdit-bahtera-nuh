<?php

use App\Actions\Signers\ManageSigner;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Signer\SignerClient;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

Artisan::command('tte:create-admin {email} {name}', function (string $email, string $name, AuditLogger $audit): int {
    if (User::query()->where('email', $email)->exists()) {
        $this->error("Pengguna {$email} sudah ada.");

        return 1;
    }
    $user = new User(['name' => $name, 'email' => $email, 'role' => UserRole::Admin, 'password' => Str::password(32)]);
    $user->forceFill(['email_verified_at' => now()])->save();
    Password::sendResetLink(['email' => $email]);
    $audit->record('user.created', $user, ['email' => $email, 'role' => 'admin', 'via' => 'console']);

    $this->info("Admin dibuat. Tautan untuk membuat password dikirim ke {$email}.");

    return 0;
})->purpose('Create the first administrator account');

Artisan::command('audit:verify', function (AuditLogger $audit): int {
    $broken = $audit->firstBrokenEntry();
    if ($broken !== null) {
        $this->error("Rantai audit RUSAK mulai entri #{$broken}.");

        return 1;
    }
    $this->info('Rantai audit utuh ('.AuditLog::query()->count().' entri).');

    return 0;
})->purpose('Verify the hash chain of the audit log (ADR-0004)');

Artisan::command('audit:anchor', function (SignerClient $signer, AuditLogger $audit): int {
    $head = AuditLog::query()->orderByDesc('id')->first(['id', 'hash']);
    if ($head === null) {
        $this->info('Audit log kosong, tidak ada yang di-anchor.');

        return 0;
    }
    $date = now()->toDateString();
    $anchor = $signer->signAuditAnchor($head->hash, $head->id, $date, SignerClient::newRequestId());

    $to = config('signer.audit_archive_email');
    if ($to) {
        $body = json_encode([
            'anchored_date' => $date,
            'last_entry_id' => $head->id,
            'chain_head_sha256' => $head->hash,
            'certificate_serial' => $anchor['certificate_serial'],
            'signed_at' => $anchor['signed_at'],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        Mail::raw("Anchor audit log harian TTE QR. Simpan email ini di luar server.\n\n{$body}", function (Message $m) use ($to, $date, $anchor): void {
            $m->to($to)->subject("Anchor audit log {$date}")
                ->attachData((string) base64_decode($anchor['cms_signature'], true), "audit-anchor-{$date}.p7s", ['mime' => 'application/pkcs7-signature']);
        });
    } else {
        $this->warn('AUDIT_ARCHIVE_EMAIL belum diatur: anchor tidak dikirim ke luar server.');
    }

    $audit->record('audit.anchored', null, [
        'last_entry_id' => $head->id,
        'chain_head_sha256' => $head->hash,
        'certificate_serial' => $anchor['certificate_serial'],
    ]);
    $this->info("Anchor dibuat untuk entri #{$head->id}.");

    return 0;
})->purpose('Sign the audit-log chain head and send it off-server (ADR-0004)');

Schedule::command('audit:verify')->dailyAt('01:00')->onOneServer();
Schedule::command('audit:anchor')->dailyAt('01:05')->onOneServer();

Artisan::command('tte:demo', function (ManageSigner $signers): int {
    if (app()->isProduction()) {
        $this->error('tte:demo hanya untuk development.');

        return 1;
    }
    $google2fa = new Google2FA;
    $loginSecret = 'JBSWY3DPEHPK3PXP'; // DEV ONLY: shared, publicly known login 2FA secret
    $make = function (string $email, string $name, string $position, UserRole $role) use ($loginSecret): User {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name, 'position' => $position, 'role' => $role, 'is_active' => true,
            'password' => 'password', 'email_verified_at' => now(),
            'two_factor_secret' => encrypt($loginSecret),
            'two_factor_recovery_codes' => encrypt(json_encode(['demo-recovery-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    };

    $admin = $make('admin@tte.local', 'Admin TU', 'Kepala Tata Usaha', UserRole::Admin);
    $make('staf@tte.local', 'Rina Staf', 'Staf Administrasi', UserRole::Staff);
    $rows = [['admin@tte.local', 'password', $loginSecret, '-']];
    foreach ([['wakasek@tte.local', 'Siti Aminah', 'Wakil Kepala Sekolah'], ['kepsek@tte.local', 'Budi Santoso', 'Kepala Sekolah']] as [$email, $name, $position]) {
        $user = $make($email, $name, $position, UserRole::Staff);
        if (! $user->hasSignerKey()) {
            $signers->enable($user, $admin);
        }
        $signSecret = '(sudah aktif sebelumnya)';
        if ($user->signer_totp_confirmed_at === null) {
            parse_str((string) parse_url($signers->startActivation($user), PHP_URL_QUERY), $q);
            $signSecret = (string) $q['secret'];
            $signers->confirmActivation($user, $google2fa->getCurrentOtp($signSecret));
        }
        $rows[] = [$email, 'password', $loginSecret, $signSecret];
    }
    $rows[] = ['staf@tte.local', 'password', $loginSecret, '-'];

    $this->table(['Email', 'Password', 'Secret 2FA login', 'Secret TOTP tanda tangan'], $rows);
    $this->warn('Data demo. Masukkan secret ke aplikasi autentikator. Kode tanda tangan pertama baru berlaku setelah 30 detik.');

    return 0;
})->purpose('Create demo users with signing enabled (development only)');
