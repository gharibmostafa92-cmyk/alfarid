import type { StaffUserResource } from '@alfarid/api-client';
import type { StaffUser } from './auth.store';

export const toStaffUser = (u: StaffUserResource): StaffUser => ({
  id: u.id,
  name: u.name,
  email: u.email,
  roles: u.roles.map((r) => r.name),
  permissions: u.permissions ?? [],
  branch_id: u.branch_id,
});
