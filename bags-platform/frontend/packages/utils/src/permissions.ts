/**
 * Check if the staff user has a specific permission.
 * Matches the staff guard's exact permission names (wildcards are disabled).
 */
export function hasPermission(
  userPermissions: string[] | null | undefined,
  requiredPermission: string
): boolean {
  if (!userPermissions || !Array.isArray(userPermissions)) {
    return false;
  }

  if (userPermissions.includes(requiredPermission)) {
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
