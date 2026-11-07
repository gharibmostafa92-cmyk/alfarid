/**
 * Check if the staff user has a specific permission.
 * Matches the staff guard's exact permission names (wildcards are disabled).
 */
export function hasPermission(
  userPermissions: string[] | null | undefined,
  requiredPermission: string
): boolean {
  return Array.isArray(userPermissions) && userPermissions.includes(requiredPermission);
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

/**
 * Check if any permission in the list is granted using a can(perm) checker function.
 * Example: canAny(can, 'branches.update', 'branches.manage')
 */
export function canAny(
  can: (permission: string) => boolean,
  ...permissions: string[]
): boolean {
  return permissions.some((perm) => can(perm));
}

/**
 * Check if all permissions in the list are granted using a can(perm) checker function.
 */
export function canAll(
  can: (permission: string) => boolean,
  ...permissions: string[]
): boolean {
  return permissions.every((perm) => can(perm));
}
