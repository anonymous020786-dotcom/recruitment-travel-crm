<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Services\PublicEnquiryService;

final class ContactController extends Controller
{
    public function __construct(private readonly PublicEnquiryService $enquiries)
    {
    }

    public function submit(Request $request): Response
    {
        return $this->enquiries->submit($request, 'contact', '/contact');
    }
}
