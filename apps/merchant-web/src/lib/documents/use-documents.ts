'use client';

import { ApiError, NetworkError } from '@naipay/api-client';
import type { ApiEnvelope } from '@naipay/shared-types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/auth/api';
import { merchantTokenStore } from '@/lib/auth/token-store';
import { env } from '@/lib/env';
import type { Document, DocumentTypeOption } from '@/lib/documents/types';

export function useDocumentTypes(owner: 'merchant' | 'business') {
  return useQuery({
    queryKey: ['document-types', owner],
    queryFn: ({ signal }) => api.get<DocumentTypeOption[]>(`/merchant/documents/types/${owner}`, { signal }),
  });
}

export function useDocumentList() {
  return useQuery({
    queryKey: ['documents', 'self'],
    queryFn: ({ signal }) => api.get<Document[]>('/merchant/documents', { signal }),
  });
}

export function useBusinessDocumentList(businessId: number | string) {
  return useQuery({
    queryKey: ['documents', 'business', String(businessId)],
    queryFn: ({ signal }) => api.get<Document[]>(`/merchant/businesses/${businessId}/documents`, { signal }),
    enabled: Boolean(businessId),
  });
}

interface UploadInput {
  documentTypeId: number;
  file: File;
  documentNumber?: string;
  issuedAt?: string;
  expiresAt?: string;
}

/**
 * Uploads a document via multipart/form-data. The shared NaipayApiClient
 * only speaks JSON bodies, so this talks to the API directly for this one
 * request — mirroring its auth, correlation and error-handling behaviour
 * rather than bypassing it.
 */
async function uploadDocument(path: string, input: UploadInput): Promise<Document> {
  const form = new FormData();
  form.set('document_type_id', String(input.documentTypeId));
  form.set('file', input.file);
  if (input.documentNumber) form.set('document_number', input.documentNumber);
  if (input.issuedAt) form.set('issued_at', input.issuedAt);
  if (input.expiresAt) form.set('expires_at', input.expiresAt);

  const token = await merchantTokenStore.get();
  const url = `${env.NEXT_PUBLIC_API_URL.replace(/\/+$/, '')}/${path.replace(/^\/+/, '')}`;

  let response: Response;

  try {
    response = await fetch(url, {
      method: 'POST',
      headers: token ? { Authorization: `Bearer ${token}` } : undefined,
      body: form,
      credentials: 'omit',
    });
  } catch (cause) {
    throw new NetworkError('Could not reach Every Merchant. Check your connection and try again.', cause);
  }

  let envelope: ApiEnvelope<Document> | null = null;

  try {
    envelope = (await response.json()) as ApiEnvelope<Document>;
  } catch {
    // Falls through to the error below.
  }

  if (!response.ok || envelope === null || envelope.success === false) {
    const message =
      envelope !== null && 'message' in envelope ? envelope.message : 'The document could not be uploaded.';
    const errors = envelope !== null && envelope.success === false ? (envelope.errors ?? {}) : {};

    throw new ApiError(message, response.status, errors, response.headers.get('X-Correlation-Id'));
  }

  return envelope.data;
}

export function useUploadDocument() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: UploadInput) => uploadDocument('/merchant/documents', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['documents', 'self'] });
    },
  });
}

export function useUploadBusinessDocument(businessId: number | string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: UploadInput) => uploadDocument(`/merchant/businesses/${businessId}/documents`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['documents', 'business', String(businessId)] });
    },
  });
}
