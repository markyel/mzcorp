<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Quotation;
use App\Services\Quotations\QuotationPdfService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Preview / download PDF КП.
 *
 *   GET /dashboard/quotations/{quotation}/preview   → inline PDF в браузере
 *   GET /dashboard/quotations/{quotation}/download  → форс-скачка
 *
 * Permission: owner / acting (delegation) / privileged (head_of_sales|director|secretary).
 */
class QuotationPdfController extends Controller
{
    public function __construct(private readonly QuotationPdfService $svc)
    {
    }

    public function preview(Quotation $quotation): Response
    {
        $this->authorizeAccess($quotation);

        return response($this->svc->render($quotation), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $this->svc->filename($quotation) . '"',
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }

    public function download(Quotation $quotation): Response
    {
        $this->authorizeAccess($quotation);
        $filename = $this->svc->filename($quotation);

        return response($this->svc->render($quotation), 200, [
            'Content-Type' => 'application/pdf',
            // RFC 6266: filename для русских символов через filename* UTF-8
            'Content-Disposition' => 'attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
        ]);
    }

    private function authorizeAccess(Quotation $quotation): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(403);
        }
        if ($user->hasAnyRole([Role::HeadOfSales->value, Role::Director->value, Role::Secretary->value, Role::Admin->value])) {
            return;
        }
        $req = $quotation->request;
        if (! $req) {
            abort(404);
        }
        $accessible = method_exists($req, 'isAccessibleBy')
            ? $req->isAccessibleBy($user)
            : $req->assigned_user_id === $user->id;
        if (! $accessible) {
            abort(403);
        }
    }
}
