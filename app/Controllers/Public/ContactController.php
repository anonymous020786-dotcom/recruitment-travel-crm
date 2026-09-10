<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Integrations\Turnstile;
use App\Repositories\PublicEnquiryRepository;
use App\Support\Logger;
use App\Validators\Validator;

final class ContactController extends Controller
{
    public function __construct(
        private readonly PublicEnquiryRepository $enquiries,
        private readonly Turnstile $turnstile,
        private readonly Logger $logger,
    ) {
    }

    public function submit(Request $request): Response
    {
        // Honeypot — real users never fill this.
        if (trim((string) $request->input('company', '')) !== '') {
            $this->logger->info('contact honeypot tripped', ['ip' => $request->ip()]);
            flash('status', 'Thanks — your message has been received.');

            return Response::redirect('/contact');
        }

        $validator = Validator::make($request->only(['name', 'phone', 'email', 'message']), [
            'name'    => 'required|string|max:150',
            'phone'   => 'required|string|min:7|max:30|regex:/^[0-9+()\-\s]{7,30}$/',
            'email'   => 'nullable|email|max:180',
            'message' => 'required|string|min:5|max:1000',
        ]);

        if ($validator->fails()) {
            return redirect_with_errors($validator->errors(), $request->all(), '/contact');
        }

        if (!$this->turnstile->verify(
            $request->input('cf-turnstile-response') ?? $request->input('cf_turnstile_response'),
            $request->ip(),
        )) {
            return redirect_with_errors(['form' => ['Please complete the verification and try again.']], $request->all(), '/contact');
        }

        // Extra per-IP flood guard beyond the rate-limit middleware.
        if ($this->enquiries->recentFromIp($request->ipBinary(), 3600) >= 5) {
            flash('status', 'Thanks — your message has been received.');

            return Response::redirect('/contact');
        }

        $data = $validator->validated();
        $this->enquiries->create([
            'type'    => 'contact',
            'name'    => (string) $data['name'],
            'phone'   => (string) $data['phone'],
            'email'   => (string) ($data['email'] ?? ''),
            'message' => (string) $data['message'],
            'meta'    => [
                'referer' => substr((string) $request->header('Referer', ''), 0, 300),
                'ua'      => $request->userAgent(),
            ],
            'ip'      => $request->ipBinary(),
        ]);

        flash('status', 'Thanks — your message has been received. We will get back to you soon.');

        return Response::redirect('/contact');
    }
}
