export interface DocumentTypeOption {
  value: number;
  label: string;
  category: string;
  is_required: boolean;
  has_expiry: boolean;
  requires_document_number: boolean;
}

/** Mirrors DocumentResource. */
export interface Document {
  id: number;
  type: { id: number; name: string; category: string; is_required: boolean } | null;
  file_name: string;
  mime_type: string;
  file_size: number;
  file_size_human: string;
  document_number: string | null;
  issued_at: string | null;
  expires_at: string | null;
  is_expired: boolean;
  verification_status: string;
  verification_status_label: string;
  satisfies_requirement: boolean;
  rejection_reason: string | null;
  version: number;
  is_current: boolean;
  uploaded_by_merchant: string | null;
  created_at: string | null;
}
