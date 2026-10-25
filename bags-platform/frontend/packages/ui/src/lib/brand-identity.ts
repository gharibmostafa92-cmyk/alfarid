export interface BrandIdentity {
  name: string;
  logo: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
}

export const DEFAULT_BRANDING: BrandIdentity = {
  name: 'Alfarid', logo: null, email: null, phone: null, address: null,
};
