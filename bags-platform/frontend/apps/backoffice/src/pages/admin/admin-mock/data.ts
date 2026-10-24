// View models for the admin API screens.

export interface AdminBranch {
  id: number;
  name: string;
}

export interface PermissionItem {
  0: string; // key
  1: string; // label
  2?: boolean; // sensitive flag
}

export interface PermissionGroup {
  module: string;
  label: string;
  keys: string;
  icon: string;
  perms: [string, string, boolean?][];
}

export interface AdminRole {
  id: number;
  name: string;
  system?: boolean;
  builtin?: boolean;
  version?: number;
  users_count?: number;
  perms: string[];
}

export interface AdminUser {
  version?: number;
  id: number;
  name: string;
  email: string;
  phone: string | null;
  branch_id: number | null;
  branch_name?: string | null;
  role_names?: string[];
  roles: number[];
  is_active: boolean;
  created_at: string;
  me?: boolean;
}

export interface DocType {
  key: string;
  label: string;
}

export interface NumberingSetting {
  prefix: string;
  padding: number;
  next_number: number;
}

export interface AdminSettings {
  company: {
    company_logo: string;
    company_name: string;
    company_email: string;
    company_phone: string;
    company_address: string;
    commercial_register: string;
  };
  tax: {
    tax_enabled: boolean;
    tax_number: string;
    default_tax_rate: number | string;
    prices_include_tax: boolean;
  };
  numbering: Record<string, NumberingSetting>;
  orders: {
    return_window_days: number;
  };
  purchases: {
    allow_self_approve_purchases: boolean;
  };
}

export const DOC_TYPES: DocType[] = [
  { key: 'invoice', label: 'فواتير البيع' },
  { key: 'order', label: 'طلبات البيع' },
  { key: 'purchase_order', label: 'أوامر الشراء' },
  { key: 'quotation', label: 'عروض الأسعار' },
  { key: 'movement', label: 'حركات المخزون' },
  { key: 'transfer', label: 'التحويلات المخزنية' },
  { key: 'stocktake', label: 'الجرد المخزني' },
  { key: 'return', label: 'المرتجعات' },
  { key: 'purchase_request', label: 'طلبات الشراء' },
  { key: 'delivery', label: 'أذون التسليم والشحن' },
  { key: 'supplier_invoice', label: 'فواتير الموردين' },
  { key: 'supplier_payment', label: 'سندات صرف الموردين' },
];

