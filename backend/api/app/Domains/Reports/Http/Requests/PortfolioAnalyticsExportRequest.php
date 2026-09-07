<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Reports\Support\PortfolioAnalyticsCsv;
use Illuminate\Validation\Rule;

/**
 * The portfolio dashboard's CSV export. Same filter query string as
 * PortfolioAnalyticsRequest, plus which slice to write, and gated on the
 * dedicated export permission.
 */
final class PortfolioAnalyticsExportRequest extends PortfolioAnalyticsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ReportsExport->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'section' => ['sometimes', 'string', Rule::in(PortfolioAnalyticsCsv::SECTIONS)],
        ]);
    }

    public function section(): string
    {
        return $this->filled('section') ? (string) $this->string('section') : 'summary';
    }
}
