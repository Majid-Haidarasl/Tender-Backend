<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Models\CmsPageVersion;
use App\Services\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminCmsController extends Controller
{
    public function index(): JsonResponse
    {
        $pages = CmsPage::with('updater:id,username,full_name')->orderBy('slug')->get();
        return $this->successResponse($pages);
    }

    public function show(string $slug): JsonResponse
    {
        $page = CmsPage::where('slug', $slug)->first();
        if (!$page) {
            return $this->notFoundResponse('صفحه یافت نشد');
        }
        return $this->successResponse($page);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $page = CmsPage::where('slug', $slug)->first();
        if (!$page) {
            return $this->notFoundResponse('صفحه یافت نشد');
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'metadata' => 'nullable|array',
            'is_published' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $admin = $request->user();
        $oldValues = $page->only(['title', 'content', 'is_published']);

        CmsPageVersion::create([
            'id' => (string) Str::uuid(),
            'cms_page_id' => $page->id,
            'version' => $page->version,
            'title' => $page->title,
            'content' => $page->content,
            'metadata' => $page->metadata,
            'created_by' => $admin->id,
        ]);

        if ($request->has('title')) $page->title = $request->input('title');
        if ($request->has('content')) $page->content = $request->input('content');
        if ($request->has('metadata')) $page->metadata = $request->input('metadata');
        if ($request->has('is_published')) $page->is_published = $request->input('is_published');
        $page->version = ($page->version ?? 1) + 1;
        $page->updated_by = $admin->id;
        $page->save();

        AdminAuditService::log(
            $admin->id, 'cms.update', 'cms_page', $page->id,
            $oldValues, $page->only(['title', 'is_published', 'version']),
            "ویرایش صفحه {$slug}", $request
        );

        return $this->successResponse($page, 'صفحه با موفقیت به‌روزرسانی شد');
    }

    public function versions(string $slug): JsonResponse
    {
        $page = CmsPage::where('slug', $slug)->first();
        if (!$page) {
            return $this->notFoundResponse('صفحه یافت نشد');
        }

        $versions = CmsPageVersion::where('cms_page_id', $page->id)
            ->with('creator:id,username,full_name')
            ->orderByDesc('version')
            ->get();

        return $this->successResponse($versions);
    }

    public function rollback(Request $request, string $slug, int $version): JsonResponse
    {
        $page = CmsPage::where('slug', $slug)->first();
        if (!$page) {
            return $this->notFoundResponse('صفحه یافت نشد');
        }

        $versionRecord = CmsPageVersion::where('cms_page_id', $page->id)
            ->where('version', $version)
            ->first();

        if (!$versionRecord) {
            return $this->notFoundResponse('نسخه یافت نشد');
        }

        $admin = $request->user();

        CmsPageVersion::create([
            'id' => (string) Str::uuid(),
            'cms_page_id' => $page->id,
            'version' => $page->version,
            'title' => $page->title,
            'content' => $page->content,
            'metadata' => $page->metadata,
            'created_by' => $admin->id,
        ]);

        $page->title = $versionRecord->title;
        $page->content = $versionRecord->content;
        $page->metadata = $versionRecord->metadata;
        $page->version = ($page->version ?? 1) + 1;
        $page->updated_by = $admin->id;
        $page->save();

        AdminAuditService::log(
            $admin->id, 'cms.rollback', 'cms_page', $page->id,
            null, ['rolled_back_to' => $version],
            "بازگردانی صفحه {$slug} به نسخه {$version}", $request
        );

        return $this->successResponse($page, 'صفحه با موفقیت بازگردانی شد');
    }
}
