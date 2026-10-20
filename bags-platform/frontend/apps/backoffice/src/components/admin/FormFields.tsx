import React from 'react';

export interface FieldProps {
  label?: string;
  req?: boolean;
  opt?: boolean;
  help?: React.ReactNode;
  error?: string | null;
  className?: string;
  children: React.ReactNode;
}

export function Field({ label, req, opt, help, error, className = '', children }: FieldProps) {
  return (
    <div className={`space-y-1.5 text-right ${className}`} dir="rtl">
      {label && (
        <label className="block text-xs font-semibold text-zinc-700">
          {label}
          {req && <span className="text-red-500 mr-1">*</span>}
          {opt && <span className="text-zinc-400 font-normal mr-1.5 text-[11px]">(اختياري)</span>}
        </label>
      )}
      {children}
      {error ? (
        <p className="text-xs text-red-600 font-medium">{error}</p>
      ) : help ? (
        <div className="text-[11px] text-zinc-500 leading-normal">{help}</div>
      ) : null}
    </div>
  );
}

export interface ToggleProps {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label?: React.ReactNode;
  disabled?: boolean;
}

export function Toggle({ checked, onChange, label, disabled }: ToggleProps) {
  return (
    <label
      className={`inline-flex items-center gap-2.5 select-none cursor-pointer ${
        disabled ? 'opacity-50 cursor-not-allowed' : ''
      }`}
    >
      <div
        onClick={() => !disabled && onChange(!checked)}
        className={`w-9 h-5 flex items-center rounded-full p-0.5 transition-colors duration-200 ease-in-out ${
          checked ? 'bg-brand-600' : 'bg-zinc-300'
        }`}
      >
        <div
          className={`bg-white w-4 h-4 rounded-full shadow-md transform transition-transform duration-200 ease-in-out ${
            checked ? '-translate-x-4' : 'translate-x-0'
          }`}
        />
      </div>
      {label && <span className="text-xs font-medium text-zinc-700">{label}</span>}
    </label>
  );
}

export interface RadioCardOption {
  value: string;
  title: string;
  description: string;
}

export interface RadioCardsProps {
  options: RadioCardOption[];
  value: string;
  onChange: (value: string) => void;
  cols?: string;
}

export function RadioCards({ options, value, onChange, cols = 'grid-cols-2' }: RadioCardsProps) {
  return (
    <div className={`grid ${cols} gap-3`} dir="rtl">
      {options.map((option) => {
        const isSelected = value === option.value;
        return (
          <div
            key={option.value}
            onClick={() => onChange(option.value)}
            className={`p-3.5 rounded-xl border text-right cursor-pointer transition-all flex items-start gap-3 ${
              isSelected
                ? 'border-brand-600 bg-brand-50/50 shadow-sm ring-1 ring-brand-500'
                : 'border-zinc-200 hover:border-zinc-300 bg-white'
            }`}
          >
            <div
              className={`w-4 h-4 rounded-full border mt-0.5 flex items-center justify-center shrink-0 ${
                isSelected ? 'border-brand-600 bg-brand-600' : 'border-zinc-300'
              }`}
            >
              {isSelected && <div className="w-1.5 h-1.5 rounded-full bg-white" />}
            </div>
            <div>
              <div className="text-xs font-bold text-zinc-900">{option.title}</div>
              <div className="text-[11px] text-zinc-500 mt-0.5 leading-snug">
                {option.description}
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}

export interface CheckProps {
  checked?: boolean;
  indeterminate?: boolean;
  onChange?: (checked: boolean) => void;
  label?: React.ReactNode;
  disabled?: boolean;
}

export function Check({ checked, indeterminate, onChange, label, disabled }: CheckProps) {
  const ref = React.useRef<HTMLInputElement>(null);

  React.useEffect(() => {
    if (ref.current) {
      ref.current.indeterminate = !!indeterminate;
    }
  }, [indeterminate]);

  return (
    <label
      className={`inline-flex items-center gap-2 select-none cursor-pointer text-xs font-medium text-zinc-700 ${
        disabled ? 'opacity-50 cursor-not-allowed' : ''
      }`}
      onClick={(e) => e.stopPropagation()}
    >
      <input
        ref={ref}
        type="checkbox"
        checked={!!checked}
        disabled={disabled}
        onChange={(e) => onChange && onChange(e.target.checked)}
        className="w-4 h-4 rounded border-zinc-300 text-brand-600 focus:ring-brand-500 rounded-sm cursor-pointer disabled:cursor-not-allowed"
      />
      {label && <span>{label}</span>}
    </label>
  );
}

export interface PasswordInputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  error?: boolean | string | null;
}

export function PasswordInput({ error, className = '', ...props }: PasswordInputProps) {
  const [show, setShow] = React.useState(false);

  return (
    <div className="relative flex items-center" dir="ltr">
      <input
        type={show ? 'text' : 'password'}
        className={`w-full px-3 py-2 text-sm rounded-lg border bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-600 pr-10 font-mono transition-colors ${
          error ? 'border-red-500' : 'border-zinc-300'
        } ${className}`}
        {...props}
      />
      <button
        type="button"
        tabIndex={-1}
        onClick={() => setShow((s) => !s)}
        className="absolute right-3 text-zinc-400 hover:text-zinc-600 transition-colors"
        aria-label={show ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'}
      >
        {show ? (
          <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
          </svg>
        ) : (
          <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268-2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
          </svg>
        )}
      </button>
    </div>
  );
}
