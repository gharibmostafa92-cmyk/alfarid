export type CustomerType = 'retail' | 'wholesale' | 'distributor';
export type CustomerStatus = 'pending' | 'approved' | 'rejected' | 'suspended';

export interface Customer {
  id: number;
  name: string;
  email: string | null;
  phone: string;
  phone_verified_at: string | null;
  type: CustomerType;
  status: CustomerStatus;
  company_name: string | null;
  customer_group_id: number | null;
  credit_limit: string | null;
  wallet_balance: string | null;
  created_at: string;
}
