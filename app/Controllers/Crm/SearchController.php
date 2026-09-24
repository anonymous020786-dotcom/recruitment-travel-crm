<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Http\Request;
use App\Http\Response;
use App\Services\GlobalSearchService;

/** Global search across the modules the signed-in user may see. */
final class SearchController extends CrmController
{
    public function __construct(private readonly GlobalSearchService $search)
    {
    }

    public function index(Request $request): Response
    {
        $q = GlobalSearchService::normalise((string) $request->query('q', ''));
        $tooShort = $q !== '' && mb_strlen($q) < GlobalSearchService::MIN_LENGTH;

        return view_response('crm.search.index', [
            'q' => $q,
            'tooShort' => $tooShort,
            'sections' => $q !== '' && !$tooShort ? $this->search->search($q, $this->currentUser(), $this->scope()) : [],
        ]);
    }
}
