<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Request;
use App\Mail\MailComposer;
use App\Models\User;
use App\Repositories\LoginHistoryRepository;
use App\Support\Logger;
use Throwable;

/**
 * Records each successful sign-in and emails the user when it comes from a
 * device fingerprint we have never seen for them (and it isn't their first
 * login). Best-effort — a failure here never blocks the login.
 */
final class LoginAlerts
{
    public function __construct(
        private readonly LoginHistoryRepository $history,
        private readonly MailComposer $mail,
        private readonly Logger $logger,
    ) {
    }

    public function afterLogin(User $user, Request $request, string $via = 'password'): void
    {
        try {
            $uaHash = hash('sha256', $request->userAgent());
            $priorLogins = $this->history->countForUser($user->id);
            $known = $priorLogins > 0 && $this->history->seenFingerprint($user->id, $uaHash);

            $shouldAlert = !$known && $priorLogins > 0 && $this->newDeviceAlertsEnabled($user);

            $this->history->record($user->id, $request->ipBinary(), $uaHash, $request->userAgent(), $via, $shouldAlert);

            if ($shouldAlert) {
                $this->mail->send($user->email, 'new-device', [
                    'subject' => 'New sign-in to your account',
                    'name'    => $user->name,
                    'ip'      => $request->ip(),
                    'device'  => $this->describeDevice($request->userAgent()),
                    'when'    => gmdate('Y-m-d H:i'),
                    'via'     => $via === 'remember' ? 'remembered device' : $via,
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error('login alert failed', ['user_id' => $user->id, 'exception' => $e]);
        }
    }

    private function newDeviceAlertsEnabled(User $user): bool
    {
        // The `notify_new_device` column lives on the users row, not the DTO.
        return (bool) (app(\App\Support\Db::class)->selectValue(
            'SELECT notify_new_device FROM users WHERE id = :id',
            ['id' => $user->id],
            1,
        ));
    }

    private function describeDevice(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'Windows')  => 'Windows',
            str_contains($ua, 'Mac OS')   => 'macOS',
            str_contains($ua, 'Android')  => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Linux')    => 'Linux',
            default => 'Unknown OS',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/')     => 'Edge',
            str_contains($ua, 'Chrome/')  => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/')  => 'Safari',
            default => 'a browser',
        };

        return "{$browser} on {$os}";
    }
}
