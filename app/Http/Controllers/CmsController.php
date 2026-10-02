<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use Illuminate\Http\JsonResponse;

class CmsController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $page = CmsPage::where('slug', $slug)->where('is_published', true)->first();

        if (!$page) {
            return $this->notFoundResponse('صفحه یافت نشد');
        }

        return $this->successResponse([
            'slug' => $page->slug,
            'title' => $page->title,
            'content' => $page->content,
            'metadata' => $page->metadata,
            'version' => $page->version,
            'updated_at' => $page->updated_at,
        ]);
    }
}
