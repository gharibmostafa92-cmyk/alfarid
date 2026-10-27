import { useState, type ChangeEvent } from 'react';

export function useFormState<T extends Record<string, any>>(initial: T) {
  const [v, setV] = useState<T>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = <K extends keyof T>(k: K, val: T[K]) => {
    setV((p) => ({ ...p, [k]: val }));
    if (errors[k as string]) setErrors((e) => { const n = { ...e }; delete n[k as string]; return n; });
  };
  const on = (k: keyof T) => (e: ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    const t = e.target as HTMLInputElement;
    const val = t.type === 'number' ? (t.value === '' ? '' : Number(t.value)) : t.type === 'checkbox' ? t.checked : t.value;
    set(k, val as T[keyof T]);
  };
  return { v, set, on, errors, setErrors };
}
