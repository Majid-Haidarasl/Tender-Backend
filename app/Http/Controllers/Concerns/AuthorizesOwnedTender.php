<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Tender;
use App\Services\TenderAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait AuthorizesOwnedTender
{
    protected function findOwnedTender(Request $request, string $tenderId): ?Tender
    {
        return TenderAccessService::findOwned($request, $tenderId);
    }

    protected function ownedTenderNotFoundResponse(): JsonResponse
    {
        return $this->notFoundResponse('مناقصه یافت نشد');
    }

    protected function requireOwnedTender(Request $request, string $tenderId): Tender|JsonResponse
    {
        $tender = $this->findOwnedTender($request, $tenderId);

        if (!$tender) {
            return $this->ownedTenderNotFoundResponse();
        }

        return $tender;
    }
}
