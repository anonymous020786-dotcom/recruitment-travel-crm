<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Cms\MediaLibrary;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;

/** Admin → Pages → Media: upload, describe, find and delete the public images. The rules live in MediaLibrary. */
final class CmsMediaController extends CrmController
{
    public function __construct(private readonly MediaLibrary $media)
    {
    }

    public function index(Request $request): Response
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $page = max(1, min((int) $request->query('page', '1'), 1000));
        $result = $this->media->page($q, $page);
        $open = (string) $request->query('file', '');
        $selected = preg_match('/^[0-9A-Z]{26}$/D', $open) === 1 ? $this->media->find($open) : null;

        return view_response('crm.admin.cms.media', [
            'rows' => $result['rows'], 'total' => $result['total'], 'bytes' => $result['bytes'], 'page' => $page, 'perPage' => MediaLibrary::PER_PAGE, 'q' => $q,
            'selected' => $selected, 'usage' => $selected === null ? [] : $this->media->usage($selected),
            'maxMb' => round((int) config('cms.media.max_kb', 8192) / 1024, 1),
            'canManage' => can('cms.manage'), 'canPublish' => can('cms.publish'),
        ]);
    }

    public function store(Request $request): Response
    {
        $file = $request->file('file');
        try {
            $r = $this->media->upload(is_array($file) ? $file : [], (string) $request->input('alt', ''), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only(['alt']), '/admin/cms/media');
        }
        flash('status', $r['reused'] ? 'That image was already in the library — here it is.' : 'Uploaded. Copy the code below into a page.');

        return Response::redirect('/admin/cms/media?file=' . $r['media']['public_id']);
    }

    public function update(Request $request, string $file): Response
    {
        try {
            $this->media->describe($file, (string) $request->input('alt', ''), (string) $request->input('title', ''), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), [], '/admin/cms/media?file=' . $file);
        } catch (DomainRuleException) {
            abort(404);
        }
        flash('status', 'Saved.');

        return Response::redirect('/admin/cms/media?file=' . $file);
    }

    public function destroy(string $file): Response
    {
        try {
            $this->media->delete($file, $this->currentUser());
        } catch (DomainRuleException $e) {
            if ($e->httpStatus() === 404) {
                abort(404);
            }

            return redirect_with_errors(['form' => [$e->getMessage()]], [], '/admin/cms/media?file=' . $file);
        }
        flash('status', 'Deleted.');

        return Response::redirect('/admin/cms/media');
    }
}
