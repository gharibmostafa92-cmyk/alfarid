const MB = 1024 * 1024;

export function validateReturnFiles(groups) {
  if (groups.some((files) => files.length > 5)) return 'الحد الأقصى 5 صور للبند.';
  const files = groups.flat();
  if (files.length > 10) return 'الحد الأقصى 10 صور إجمالي.';
  if (files.some((f) => !['image/jpeg', 'image/png', 'image/webp'].includes(f.type))) return 'الأنواع المسموحة JPG / PNG / WebP فقط.';
  if (files.some((f) => f.size > 5 * MB)) return 'الحد الأقصى 5 ميجابايت للصورة.';
  if (files.reduce((sum, f) => sum + f.size, 0) > 25 * MB) return 'إجمالي حجم الصور حتى 25 ميجابايت.';
  return null;
}
