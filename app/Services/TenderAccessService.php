<?php

namespace App\Services;

use App\Models\Bidder;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class TenderAccessService
{
    public static function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    public static function userId(Request $request): ?string
    {
        return self::user($request)?->id;
    }

    public static function ownedQuery(Request $request): Builder
    {
        $userId = self::userId($request);

        if (!$userId) {
            return Tender::query()->whereRaw('0 = 1');
        }

        return Tender::query()->where('user_id', $userId);
    }

    public static function findOwned(Request $request, string $tenderId): ?Tender
    {
        return self::ownedQuery($request)->where('id', $tenderId)->first();
    }

    public static function canAccess(Request $request, string $tenderId): bool
    {
        return self::findOwned($request, $tenderId) !== null;
    }

    public static function canAccessEstimate(Request $request, Estimate $estimate): bool
    {
        return self::canAccess($request, $estimate->tender_id);
    }

    public static function canAccessBidder(Request $request, Bidder $bidder): bool
    {
        return self::canAccess($request, $bidder->tender_id);
    }

    public static function canAccessIndex(Request $request, Index $index): bool
    {
        return self::canAccess($request, $index->tender_id);
    }

    public static function dashboardCacheKey(string $userId): string
    {
        return 'dashboard_stats_v1_' . $userId;
    }
}
