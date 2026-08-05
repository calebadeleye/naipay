<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Controllers;

use App\Domains\Identity\Models\Staff;
use App\Domains\LoanApplications\Http\Requests\StoreLoanApplicationGuarantorRequest;
use App\Domains\LoanApplications\Http\Resources\LoanApplicationGuarantorResource;
use App\Domains\LoanApplications\Models\Guarantor;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\LoanApplications\Services\LoanApplicationService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LoanApplicationGuarantorController
{
    public function __construct(
        private readonly LoanApplicationService $applications,
    ) {}

    public function index(Request $request, LoanApplication $application): JsonResponse
    {
        $this->authoriseAccess($request, $application);

        return ApiResponse::success(
            LoanApplicationGuarantorResource::collection($application->guarantors()->get())->resolve(),
            'Guarantors retrieved.',
        );
    }

    public function store(StoreLoanApplicationGuarantorRequest $request, LoanApplication $application): JsonResponse
    {
        $this->authoriseAccess($request, $application);

        /** @var Staff $actor */
        $actor = $request->user();

        $guarantor = $this->applications->addGuarantor($application, $request->validated(), $actor);

        return ApiResponse::created(
            new LoanApplicationGuarantorResource($guarantor),
            "{$guarantor->full_name} added as a guarantor.",
        );
    }

    public function destroy(Request $request, LoanApplication $application, Guarantor $guarantor): JsonResponse
    {
        $this->authoriseAccess($request, $application);
        $this->authoriseBelongsToApplication($application, $guarantor);

        /** @var Staff $actor */
        $actor = $request->user();

        $this->applications->removeGuarantor($application, $guarantor, $actor);

        return ApiResponse::noContent('Guarantor removed.');
    }

    private function authoriseAccess(Request $request, LoanApplication $application): void
    {
        /** @var Staff $actor */
        $actor = $request->user();

        abort_unless(
            $actor->canAccessBranch($application->branch_id),
            404,
            'The requested loan application was not found.',
        );
    }

    /**
     * Route model binding resolves a guarantor by id regardless of which
     * application it belongs to.
     */
    private function authoriseBelongsToApplication(LoanApplication $application, Guarantor $guarantor): void
    {
        abort_unless(
            $guarantor->loan_application_id === $application->id,
            404,
            'The requested guarantor was not found.',
        );
    }
}
