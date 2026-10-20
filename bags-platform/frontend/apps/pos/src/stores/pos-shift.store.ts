import { create } from 'zustand';

export interface ShiftInfo {
  id: number;
  branchId: number;
  branchName: string;
  cashierName: string;
  openedAt: string;
  openingCash: number;
  status: 'open' | 'closed';
}

interface PosShiftState {
  currentShift: ShiftInfo | null;
  isOpen: boolean;
  openShift: (shift: ShiftInfo) => void;
  closeShift: () => void;
}

export const usePosShiftStore = create<PosShiftState>((set) => ({
  currentShift: {
    id: 1,
    branchId: 1,
    branchName: 'فرع وسط البلد',
    cashierName: 'محمد أحمد',
    openedAt: new Date().toISOString(),
    openingCash: 500,
    status: 'open',
  },
  isOpen: true,
  openShift: (shift) => set({ currentShift: shift, isOpen: true }),
  closeShift: () => set({ currentShift: null, isOpen: false }),
}));
