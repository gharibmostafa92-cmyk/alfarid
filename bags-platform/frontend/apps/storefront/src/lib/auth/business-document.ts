const MAX_DOCUMENT_BYTES = 5 * 1024 * 1024;

const MIME_BY_EXTENSION: Record<string, readonly string[]> = {
  pdf: ['application/pdf'],
  jpg: ['image/jpeg'],
  jpeg: ['image/jpeg'],
  png: ['image/png'],
};

/** Client-side convenience check; the API still validates the uploaded file contents. */
export function isAcceptedBusinessDocument(file: { name: string; type: string; size: number }): boolean {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
  const acceptedMimeTypes = MIME_BY_EXTENSION[extension];
  if (!acceptedMimeTypes || file.size <= 0 || file.size > MAX_DOCUMENT_BYTES) return false;

  const mimeType = file.type.trim().toLowerCase();
  return mimeType === '' || acceptedMimeTypes.includes(mimeType);
}
