<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Http\Controllers;

use App\Domains\Businesses\Enums\CategoryStatus;
use App\Domains\Businesses\Http\Requests\ReorderCategoriesRequest;
use App\Domains\Businesses\Http\Requests\StoreBusinessCategoryRequest;
use App\Domains\Businesses\Http\Requests\UpdateBusinessCategoryRequest;
use App\Domains\Businesses\Http\Resources\BusinessCategoryResource;
use App\Domains\Businesses\Models\BusinessCategory;
use App\Domains\Businesses\Services\BusinessCategoryService;
use App\Domains\Identity\Models\Staff;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BusinessCategoryController
{
    public function __construct(
        private readonly BusinessCategoryService $categories,
    ) {}

    /**
     * The full vocabulary, for the administration screen.
     */
    public function index(Request $request): JsonResponse
    {
        $specification = QuerySpecification::make(
            searchable: ['name', 'slug', 'description'],
            filters: [
                'status' => FilterType::In,
                'parent_id' => FilterType::Exact,
            ],
            sortable: ['name', 'display_order', 'status', 'created_at'],
            defaultSort: ['display_order', 'name'],
        );

        $query = BusinessCategory::query()->with('parent')->withCount('children');

        // Default to the top level so the screen opens as a tree rather than a
        // flat list of several hundred rows.
        if (! $request->has('parent_id') && ! $request->boolean('flat')) {
            $query->roots()->with(['children' => fn ($children) => $children->ordered()]);
        }

        $categories = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $categories->through(fn (BusinessCategory $category) => new BusinessCategoryResource($category)),
            message: 'Business categories retrieved.',
        );
    }

    /**
     * Active categories and subcategories, shaped for the searchable dropdown
     * on the merchant onboarding form.
     *
     * This is the only list an onboarding officer ever sees — the form offers
     * no way to type a new category, because free text would make every
     * category-based report and risk model worthless within a month.
     */
    public function options(): JsonResponse
    {
        return ApiResponse::success(
            $this->categories->selectableTree(),
            'Business category options retrieved.',
        );
    }

    public function store(StoreBusinessCategoryRequest $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $category = $this->categories->create($request->validated(), $actor);

        return ApiResponse::created(
            new BusinessCategoryResource($category->load('parent')),
            "Category “{$category->name}” created.",
        );
    }

    public function show(BusinessCategory $category): JsonResponse
    {
        return ApiResponse::success(
            new BusinessCategoryResource($category->load(['parent', 'children'])),
            'Business category retrieved.',
        );
    }

    public function update(UpdateBusinessCategoryRequest $request, BusinessCategory $category): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->categories->update($category, $request->validated(), $actor);

        return ApiResponse::success(
            new BusinessCategoryResource($updated->load('parent')),
            'Category updated.',
        );
    }

    /**
     * Activates or deactivates a category.
     *
     * There is no delete endpoint: businesses already filed under a category
     * keep their classification, and historical reports stay comparable.
     */
    public function changeStatus(Request $request, BusinessCategory $category): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'status' => ['required', Rule::enum(CategoryStatus::class)],
        ]);

        $updated = $this->categories->changeStatus(
            $category,
            CategoryStatus::from($validated['status']),
            $actor,
        );

        return ApiResponse::success(
            new BusinessCategoryResource($updated),
            "Category “{$updated->name}” is now {$updated->status->label()}.",
        );
    }

    public function reorder(ReorderCategoriesRequest $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $this->categories->reorder(
            array_map(intval(...), $request->input('ordered_ids', [])),
            $request->input('parent_id') === null ? null : $request->integer('parent_id'),
            $actor,
        );

        return ApiResponse::success(message: 'Categories reordered.');
    }
}
