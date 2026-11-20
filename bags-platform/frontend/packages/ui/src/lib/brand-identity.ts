export interface BrandIdentity {
  name: string;
  legalName?: string | null;
  logo: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  commercialRegister?: string | null;
  taxNumber?: string | null;
}

export const DEFAULT_BRANDING: BrandIdentity = {
  name: 'VARYA', legalName: null, logo: null, email: null, phone: null, address: null,
  commercialRegister: null, taxNumber: null,
};
