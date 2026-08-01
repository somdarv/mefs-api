<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Concerns\AuthorizesPermissions;
use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Http\Responses\ApiResponse;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The storefront's promotional strip.
 *
 * ⚠️ NO AUDIT ROWS ON THIS CONTROLLER, DELIBERATELY.
 *
 * Nobody is ever going to ask who changed a banner's wording, and the audit table's own
 * comment says why that matters: a log of everything buries the handful of acts anyone
 * actually comes looking for. This is copy. It is reverted by typing over it.
 */
final class BannerController extends Controller
{
    use AuthorizesPermissions;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::BannersManage);

        // Everything, including expired and switched-off ones — this is the editor, and a
        // list that hid what is not currently showing would make a scheduled banner
        // disappear the moment it was saved.
        $banners = Banner::query()->orderBy('position')->orderBy('id')->get();

        return ApiResponse::success(
            ['banners' => BannerResource::collection($banners)],
            'Banners',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::BannersManage);

        $banner = new Banner($request->validate($this->rules()));

        // Column defaults are not read back on a freshly saved model — the same trap
        // `PromoController::store()` and `CheckoutSessionController::store()` both carry a
        // note about.
        $banner->tone ??= 'brand';
        $banner->position ??= 0;
        $banner->is_active ??= true;

        $banner->created_by = $request->user()?->id;
        $banner->save();

        return ApiResponse::created(new BannerResource($banner), 'Banner created');
    }

    public function update(Request $request, Banner $banner): JsonResponse
    {
        $this->authorizePermission($request, Permission::BannersManage);

        $banner->update($request->validate($this->rules(creating: false)));

        return ApiResponse::success(new BannerResource($banner->refresh()), 'Banner updated');
    }

    /** Nothing points at a banner, so a delete is a delete. */
    public function destroy(Request $request, Banner $banner): JsonResponse
    {
        $this->authorizePermission($request, Permission::BannersManage);

        $banner->delete();

        return ApiResponse::success(null, 'Banner deleted');
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating = true): array
    {
        $presence = $creating ? 'required' : 'sometimes';

        return [
            'eyebrow' => ['nullable', 'string', 'max:40'],
            'title' => [$presence, 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:240'],

            /*
             * ⚠️ RELATIVE PATHS AND ON-PAGE ANCHORS ONLY.
             *
             * A banner is authored in the back office and rendered on the storefront, so an
             * absolute URL here is an open-redirect surface pointed at customers by somebody
             * holding a content permission rather than a security one.
             *
             * `#menu-heading` is allowed because the storefront is one page and most banners
             * scroll to a section on it rather than navigating. `//evil.test` is refused by
             * requiring a word character after the leading slash — a protocol-relative URL
             * is an absolute one wearing a relative one's clothes, and it is the specific
             * bypass a naive "must start with /" check misses.
             */
            'link_url' => ['nullable', 'string', 'max:200', 'regex:/^(#[\w\-]+|\/[\w\-][\w\-\/?=&.]*)$/'],
            'link_label' => ['nullable', 'string', 'max:40', 'required_with:link_url'],

            // A short list rather than free colour input: a banner with a hand-picked hex
            // stops matching the brand the first time the palette moves.
            'tone' => ['sometimes', Rule::in(['brand', 'deep', 'soft'])],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],

            'position' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
