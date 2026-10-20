/**
 * Check if the staff user has a specific permission.
 * Supports exact permission matches and wildcard matches (e.g. '*' or 'orders.*').
 */
export function hasPermission(
  userPermissions: string[] | null | undefined,
  requiredPermission: string
): boolean {
  if (!userPermissions || !Array.isArray(userPermissions)) {
    return false;
  }

  if (userPermissions.includes('*') || userPermissions.includes('super_admin')) {
    return true;
  }

  if (userPermissions.includes(requiredPermission)) {
    return true;
  }

  const [module] = requiredPermission.split('.');
  if (module && userPermissions.includes(`${module}.*`)) {
    return true;
  }

  return false;
}

export function hasAnyPermission(
  userPermissions: string[] | null | undefined,
  requiredPermissions: string[]
): boolean {
  return requiredPermissions.some((perm) => hasPermission(userPermissions, perm));
}

export function hasAllPermissions(
  userPermissions: string[] | null | undefined,
  requiredPermissions: string[]
): boolean {
  return requiredPermissions.every((perm) => hasPermission(userPermissions, perm));
}
