export const PASSWORD_BYTE_LIMIT = 72;

export function passwordByteLength(password: string): number {
  return new TextEncoder().encode(password).byteLength;
}
