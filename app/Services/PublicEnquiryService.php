<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Request;
use App\Http\Response;
use App\Integrations\Turnstile;
use App\Notifications\NotificationService;
use App\Repositories\PublicEnquiryRepository;
use App\Repositories\UserRepository;
use App\Support\Logger;
use App\Validators\Validator;

/**
 * One anti-abuse pipeline for every public form (contact, job application, travel enquiry):
 * honeypot → validation → Turnstile → per-IP flood guard → store. A bot that trips the honeypot or the flood guard is
 * told "thanks" like everyone else (nothing to learn from), and nothing is stored.
 */
final class PublicEnquiryService
{
    /** Roles told about every new enquiry (the inbox is organisation-wide, so it goes to the people who run it). */
    private const NOTIFY_ROLES = ['super_admin', 'admin', 'manager'];
    private const THANKS = 'Thanks — your message has been received. We will get back to you soon.';

    public function __construct(
        private readonly PublicEnquiryRepository $enquiries,
        private readonly Turnstile $turnstile,
        private readonly Logger $logger,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param 'contact'|'job_apply'|'travel_enquiry' $type
     * @param array<string,mixed> $meta extra context stored with the enquiry (slug of the job/package…)
     * @param string $backTo where to send the visitor afterwards / on errors
     */
    public function submit(Request $request, string $type, string $backTo, ?int $jobId = null, ?int $packageId = null, array $meta = []): Response
    {
        if (trim((string) $request->input('company', '')) !== '') {
            $this->logger->info('public form honeypot tripped', ['ip' => $request->ip(), 'type' => $type]);
            flash('status', self::THANKS);

            return Response::redirect($backTo);
        }

        $validator = Validator::make($request->only(['name', 'phone', 'email', 'message']), [
            'name'    => 'required|string|max:150',
            'phone'   => 'required|string|min:7|max:30|regex:/^[0-9+()\-\s]{7,30}$/',
            'email'   => 'nullable|email|max:180',
            'message' => ($type === 'contact' ? 'required' : 'nullable') . '|string|min:5|max:1000',
        ]);
        if ($validator->fails()) {
            return redirect_with_errors($validator->errors(), $request->all(), $backTo);
        }

        if (!$this->turnstile->verify(
            $request->input('cf-turnstile-response') ?? $request->input('cf_turnstile_response'),
            $request->ip(),
        )) {
            return redirect_with_errors(['form' => ['Please complete the verification and try again.']], $request->all(), $backTo);
        }

        if ($this->enquiries->recentFromIp($request->ipBinary(), 3600) >= 5) {
            flash('status', self::THANKS);

            return Response::redirect($backTo);
        }

        $data = $validator->validated();
        $id = $this->enquiries->create([
            'type'            => $type,
            'job_id'          => $jobId,
            'tour_package_id' => $packageId,
            'name'            => (string) $data['name'],
            'phone'           => (string) $data['phone'],
            'email'           => (string) ($data['email'] ?? ''),
            'message'         => (string) ($data['message'] ?? ''),
            'meta'            => $meta + [
                'referer' => substr((string) $request->header('Referer', ''), 0, 300),
                'ua'      => $request->userAgent(),
            ],
            'ip'              => $request->ipBinary(),
        ]);
        $this->notifyStaff($id, $type, (string) $data['name']);

        flash('status', self::THANKS);

        return Response::redirect($backTo);
    }

    private function notifyStaff(int $id, string $type, string $name): void
    {
        $what = match ($type) {
            'job_apply'      => 'Job application',
            'travel_enquiry' => 'Package enquiry',
            default          => 'Website message',
        };
        $userIds = [];
        foreach (self::NOTIFY_ROLES as $role) {
            $userIds = array_merge($userIds, $this->users->activeIdsByRole($role));
        }
        foreach (array_unique($userIds) as $userId) {
            $this->notifications->notify(userId: $userId, type: 'enquiry_new', title: "{$what} from {$name}", body: 'Open the website enquiries inbox to follow up.', linkType: 'enquiry', linkId: $id);
        }
    }
}
