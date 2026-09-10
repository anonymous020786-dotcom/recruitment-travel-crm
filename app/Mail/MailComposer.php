<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Application;
use App\View\View;

/**
 * Renders transactional emails from `resources/views/mail/*` through a shared
 * email-safe layout, then queues them via the Mailer.
 */
final class MailComposer
{
    public function __construct(
        private readonly Application $app,
        private readonly View $view,
        private readonly Mailer $mailer,
    ) {
    }

    /**
     * @param array<string,mixed> $data must include `subject`
     * @return bool accepted for delivery
     */
    public function send(string $to, string $template, array $data): bool
    {
        $subject = (string) ($data['subject'] ?? $this->app->config()->get('app.name', 'CRM'));

        $html = $this->view->render("mail.{$template}", $data + [
            'appName' => (string) $this->app->config()->get('app.name', 'CRM'),
            'appUrl'  => rtrim((string) $this->app->config()->get('app.url', ''), '/'),
        ]);

        return $this->mailer->send($to, $subject, $html, null, $template);
    }
}
