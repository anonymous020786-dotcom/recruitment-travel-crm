<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\NotificationRepository;

/** A user's own notifications: list, open (marks read and goes to the record), mark all read. */
final class NotificationController extends CrmController
{
    private const PER_PAGE = 30;

    public function __construct(private readonly NotificationRepository $notifications)
    {
    }

    public function index(Request $request): Response
    {
        $unreadOnly = $request->query('filter') === 'unread';
        $page = max(1, min((int) $request->query('page', '1'), 1000));
        $result = $this->notifications->page($this->currentUser()->id, $unreadOnly, $page, self::PER_PAGE);

        return view_response('crm.notifications.index', [
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => self::PER_PAGE,
            'unreadOnly' => $unreadOnly, 'unread' => $this->notifications->unreadCount($this->currentUser()->id),
        ]);
    }

    /** Marks the notification read, then sends the user to the record it is about (or back to the list if it is gone). */
    public function open(string $id): Response
    {
        $userId = $this->currentUser()->id;
        $n = ctype_digit($id) ? $this->notifications->findOwn((int) $id, $userId) : null;
        if ($n === null) {
            abort(404, 'Notification not found.');
        }

        $this->notifications->markRead((int) $n['id'], $userId);
        $url = $this->notifications->targetUrl(
            (string) $n['type'],
            $n['link_type'] !== null ? (string) $n['link_type'] : null,
            $n['link_id'] !== null ? (int) $n['link_id'] : null,
            $n['link_fragment'] !== null ? (string) $n['link_fragment'] : null,
        );
        if ($url === null) {
            flash('status', 'That record is no longer available.');
        }

        return Response::redirect($url ?? '/notifications');
    }

    public function readAll(): Response
    {
        $n = $this->notifications->markAllRead($this->currentUser()->id);
        flash('status', $n > 0 ? "Marked {$n} notification" . ($n === 1 ? '' : 's') . ' as read.' : 'Nothing to mark.');

        return Response::redirect('/notifications');
    }
}
