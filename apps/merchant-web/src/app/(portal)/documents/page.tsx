'use client';

import { ApiError } from '@naipay/api-client';
import { useState } from 'react';

import { Alert } from '@/components/ui/field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { QueryState } from '@/components/ui/query-state';
import { SelectField } from '@/components/ui/select';
import { useDocumentList, useDocumentTypes, useUploadDocument } from '@/lib/documents/use-documents';
import { formatDateTime } from '@/lib/format';

const statusTone: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  pending: 'neutral',
  verified: 'success',
  rejected: 'danger',
  superseded: 'neutral',
  expired: 'warning',
};

function UploadForm() {
  const { data: types } = useDocumentTypes('merchant');
  const upload = useUploadDocument();
  const [typeId, setTypeId] = useState('');
  const [file, setFile] = useState<File | null>(null);

  const error = upload.error as ApiError | null;
  const selectedType = types?.find((type) => String(type.value) === typeId);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    if (!file || !typeId) return;

    const uploaded = await upload.mutateAsync({ documentTypeId: Number(typeId), file }).catch(() => null);

    if (uploaded) {
      setTypeId('');
      setFile(null);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {error ? (
        <Alert tone="error" reference={error.correlationId}>
          {error.message}
        </Alert>
      ) : null}

      <SelectField
        label="Document type"
        placeholder="Select a document type"
        options={(types ?? []).map((type) => ({ value: String(type.value), label: type.label }))}
        value={typeId}
        onChange={(event) => setTypeId(event.target.value)}
      />

      <div className="space-y-1.5">
        <label className="block text-sm font-medium text-slate-800">File</label>
        <input
          type="file"
          accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"
          onChange={(event) => setFile(event.target.files?.[0] ?? null)}
          className="block w-full text-sm text-slate-700 file:mr-4 file:rounded-md file:border-0 file:bg-accent-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-accent-700 hover:file:bg-accent-100"
        />
        <p className="text-xs text-slate-500">PDF, JPEG, PNG, WebP or HEIC, up to 10MB.</p>
      </div>

      <Button type="submit" loading={upload.isPending} disabled={!file || !typeId}>
        Upload{selectedType ? ` ${selectedType.label}` : ''}
      </Button>
    </form>
  );
}

export default function DocumentsPage() {
  const { data: documents, isLoading, error } = useDocumentList();

  return (
    <>
      <PageHeader title="Documents" description="Identity and verification documents on file with Every Merchant." />

      <Card className="max-w-2xl">
        <h2 className="mb-4 text-sm font-semibold text-slate-900">Upload a document</h2>
        <UploadForm />
      </Card>

      <QueryState isLoading={isLoading} error={error}>
        {documents ? (
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Document</th>
                  <th className="px-4 py-3">Uploaded</th>
                  <th className="px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {documents.map((document) => (
                  <tr key={document.id} className="border-b border-slate-100 last:border-0">
                    <td className="px-4 py-3">
                      <p className="font-medium text-slate-900">{document.type?.name ?? document.file_name}</p>
                      <p className="text-xs text-slate-500">{document.file_size_human}</p>
                    </td>
                    <td className="px-4 py-3 text-slate-700">{formatDateTime(document.created_at)}</td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone[document.verification_status] ?? 'neutral'}>
                        {document.verification_status_label}
                      </Badge>
                      {document.rejection_reason ? (
                        <p className="mt-1 text-xs text-danger">{document.rejection_reason}</p>
                      ) : null}
                    </td>
                  </tr>
                ))}
                {documents.length === 0 ? (
                  <tr>
                    <td colSpan={3} className="px-4 py-10 text-center text-slate-500">
                      No documents uploaded yet.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </Card>
        ) : null}
      </QueryState>
    </>
  );
}
