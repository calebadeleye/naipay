<?php

declare(strict_types=1);

namespace App\Domains\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A merchant uploading their own document through the self-service portal.
 *
 * Identical validation to the staff-facing UploadDocumentRequest, minus the
 * permission check — ownership of the uploaded-to record is implicit
 * (checked by the controller) rather than gated by a staff permission.
 */
final class UploadMerchantDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) config('naipay.documents.max_upload_kilobytes', 10240);

        return [
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')],

            /*
             * The mimes rule is a first pass only. It trusts the extension and
             * the client-supplied content type, both of which an attacker
             * controls, so DocumentStorage re-determines the type from the
             * file's own magic bytes and rejects a mismatch.
             */
            'file' => ['required', 'file', "max:{$maxKilobytes}", 'mimes:pdf,jpg,jpeg,png,webp,heic'],

            'document_number' => ['nullable', 'string', 'max:100'],
            'issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'The document must not be larger than 10MB.',
            'file.mimes' => 'Accepted formats are PDF, JPEG, PNG, WebP and HEIC.',
            'expires_at.after' => 'An already-expired document cannot be filed. Ask for a current one.',
        ];
    }
}
