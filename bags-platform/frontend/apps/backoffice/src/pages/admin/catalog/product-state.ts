const fileIds = new WeakMap<object, number>();
let nextFileId = 0;

function isFileLike(value: unknown): value is File {
  return !!value && typeof value === 'object' &&
    typeof (value as File).name === 'string' &&
    typeof (value as File).size === 'number' &&
    typeof (value as File).arrayBuffer === 'function';
}

/** Clone form data while retaining the original File/Blob references. */
export function cloneProductState<T>(value: T): T {
  if (!value || typeof value !== 'object' || isFileLike(value)) return value;
  if (Array.isArray(value)) return value.map(cloneProductState) as T;
  const clone: Record<string, unknown> = {};
  for (const [key, item] of Object.entries(value as Record<string, unknown>)) {
    clone[key] = cloneProductState(item);
  }
  return clone as T;
}

function fileIdentity(file: File): number {
  let id = fileIds.get(file);
  if (id === undefined) {
    id = ++nextFileId;
    fileIds.set(file, id);
  }
  return id;
}

function comparable(value: unknown): unknown {
  if (isFileLike(value)) return { $fileIdentity: fileIdentity(value) };
  if (Array.isArray(value)) return value.map(comparable);
  if (!value || typeof value !== 'object') return value;

  const record = value as Record<string, unknown>;
  const isMediaItem = 'status' in record && ('file' in record || 'serverId' in record);
  const ignored = new Set(['createKey', 'clientUploadId', 'progress', 'pct', 'message']);
  if (isMediaItem) ignored.add('status');
  return Object.fromEntries(Object.keys(record).sort()
    .filter((key) => !ignored.has(key))
    .map((key) => [key, comparable(record[key])]));
}

/** Stable dirty-check fingerprint that distinguishes File objects by identity. */
export function productFingerprint(value: unknown): string {
  return JSON.stringify(comparable(value));
}
