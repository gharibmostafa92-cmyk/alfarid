'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import {
  accountEndpoints,
  checkoutEndpoints,
  type CheckoutPaymentMethod,
  type ShippingOptionsResponse,
  type StoreCity,
  type CheckoutPayload,
} from '@alfarid/api-client';
import { getCartSession, useCartStore } from '@/stores/cart-store';
import { useAuthStore } from '@/store/auth.store';
import { useAuthHydrated } from '@/hooks/use-auth-hydrated';
import { viewerKey } from '@/lib/viewer-key';

export interface SavedAddressItem {
  id: number;
  city_id: number;
  address_line: string;
  is_default?: boolean;
  label?: string;
}

export interface CheckoutForm {
  fullName: string;
  phone: string;
  email: string;
  cityId: number | null;
  street: string;
  building: string;
  floor: string;
  apartment: string;
  notes: string;
  paymentMethod: CheckoutPaymentMethod;
  agreeTerms: boolean;
}

const initialForm: CheckoutForm = {
  fullName: '',
  phone: '',
  email: '',
  cityId: null,
  street: '',
  building: '',
  floor: '',
  apartment: '',
  notes: '',
  paymentMethod: 'cod',
  agreeTerms: false,
};

/** Server field -> form field, for 422 mapping. */
const fieldMap: Record<string, keyof CheckoutForm | 'banner'> = {
  recipient_name: 'fullName',
  recipient_phone: 'phone',
  recipient_email: 'email',
  city_id: 'cityId',
  shipping_address_line: 'street',
  shipping_notes: 'notes',
  payment_method: 'paymentMethod',
  expected_total: 'banner',
};

export function buildAddressLine(
  form: Pick<CheckoutForm, 'street' | 'building' | 'floor' | 'apartment'>,
) {
  return [
    form.street.trim(),
    form.building.trim() && `Bldg ${form.building.trim()}`,
    form.floor.trim() && `Floor ${form.floor.trim()}`,
    form.apartment.trim() && `Apt ${form.apartment.trim()}`,
  ]
    .filter(Boolean)
    .join(', ');
}

export function useCheckout() {
  const router = useRouter();
  const params = useSearchParams();
  const [form, setForm] = useState<CheckoutForm>(initialForm);
  const [touched, setTouched] = useState<Partial<Record<keyof CheckoutForm, boolean>>>({});
  const [fieldErrors, setFieldErrors] = useState<Partial<Record<keyof CheckoutForm, string>>>({});
  const [banner, setBanner] = useState<{
    kind: 'error' | 'info';
    text: string;
    action?: { label: string; onClick: () => void };
  } | null>(null);
  const [cities, setCities] = useState<StoreCity[] | null>(null);
  const [savedAddresses, setSavedAddresses] = useState<SavedAddressItem[]>([]);
  const [quote, setQuote] = useState<ShippingOptionsResponse | null>(null);
  const [quoteLoading, setQuoteLoading] = useState(false);
  const [citiesError, setCitiesError] = useState(false);
  const [quoteError, setQuoteError] = useState(false);
  const [quoteRevision, refreshQuote] = useState(0);
  const [quotedFor, setQuotedFor] = useState('');
  const [uncertain, setUncertain] = useState(false);
  const submittingRef = useRef(false);
  const pending = useRef<{ body: CheckoutPayload; session: string | null } | null>(null);
  const [promoInput, setPromoInput] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const keyRef = useRef<string>(
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
      ? crypto.randomUUID()
      : `${Date.now()}-${Math.random()}`,
  );
  const promoTried = useRef(false);

  const items = useCartStore((s) => s.items);
  const cartLoading = useCartStore((s) => s.loading);
  const cartBusy = useCartStore((s) => s.busy);
  const cartError = useCartStore((s) => s.error);
  const totals = useCartStore((s) => s.totals);
  const couponCode = useCartStore((s) => s.couponCode);
  const loadCart = useCartStore((s) => s.loadCart);
  const updateQuantity = useCartStore((s) => s.updateQuantity);
  const removeItem = useCartStore((s) => s.removeItem);
  const applyCoupon = useCartStore((s) => s.applyCoupon);
  const removeCoupon = useCartStore((s) => s.removeCoupon);
  const user = useAuthStore((s) => s.user);
  const token = useAuthStore((s) => s.token);
  const hydrated = useAuthHydrated();
  const owner = viewerKey(token);
  const attemptOwner = token
    ? `${useAuthStore.getState().actorType}:${String(user?.id ?? 'unknown')}`
    : 'guest';
  const quoteKey = JSON.stringify([
    owner,
    form.cityId,
    form.paymentMethod,
    couponCode,
    items,
    quoteRevision,
  ]);
  const liveQuote = quotedFor === quoteKey && !cartBusy && !cartLoading ? quote : null;

  // A reload after a lost response must recover the same attempt, never create a second order.
  useEffect(() => {
    if (!hydrated) return;
    pending.current = null;
    setUncertain(false);
    try {
      const saved = JSON.parse(sessionStorage.getItem('pending_checkout') ?? 'null');
      if (
        saved?.owner === attemptOwner &&
        typeof saved.key === 'string' &&
        saved.body?.city_id &&
        saved.body?.recipient_phone &&
        Number.isFinite(saved.body.expected_total)
      ) {
        keyRef.current = saved.key;
        pending.current = { body: saved.body, session: saved.session ?? null };
        setUncertain(true);
        setBanner({
          kind: 'info',
          text: 'في محاولة طلب سابقة لم يتم تأكيدها. اضغط إعادة المحاولة لتأكيد نفس الطلب.',
        });
        setForm((f) => ({
          ...f,
          fullName: saved.body.recipient_name,
          phone: saved.body.recipient_phone,
          email: saved.body.recipient_email ?? '',
          cityId: saved.body.city_id,
          street: saved.body.shipping_address_line,
          notes: saved.body.shipping_notes ?? '',
          paymentMethod: saved.body.payment_method,
          agreeTerms: true,
        }));
      }
    } catch {
      /* Storage is optional; the in-memory attempt remains safe. */
    }
  }, [hydrated, attemptOwner]);

  const loadCities = useCallback(() => {
    setCitiesError(false);
    setCities(null);
    checkoutEndpoints
      .cities()
      .then(setCities)
      .catch(() => {
        setCities([]);
        setCitiesError(true);
      });
  }, []);

  const set = useCallback(<K extends keyof CheckoutForm>(key: K, value: CheckoutForm[K]) => {
    setForm((f) => ({ ...f, [key]: value }));
    setFieldErrors((e) => ({ ...e, [key]: undefined }));
  }, []);

  const blur = useCallback((key: keyof CheckoutForm) => {
    setTouched((t) => ({ ...t, [key]: true }));
  }, []);

  const applySavedAddress = useCallback((addr: SavedAddressItem) => {
    setForm((f) => ({
      ...f,
      cityId: addr.city_id,
      street: addr.address_line,
    }));
    setFieldErrors((e) => ({ ...e, cityId: undefined, street: undefined }));
  }, []);

  // Prefill contact fields for signed-in shoppers.
  useEffect(() => {
    if (!user) return;
    setForm((f) => ({
      ...f,
      fullName: f.fullName || (user.name as string) || '',
      email: f.email || (user.email as string) || '',
      phone: f.phone || (user.phone as string) || '',
    }));
  }, [user]);

  // Default saved address (first default) for signed-in shoppers, plus list for one-tap switching.
  useEffect(() => {
    if (!token) {
      setSavedAddresses([]);
      return;
    }
    let cancelled = false;
    accountEndpoints
      .addresses()
      .then((list) => {
        if (cancelled || list.length === 0) return;
        setSavedAddresses(list as SavedAddressItem[]);
        const d = list.find((a) => a.is_default) ?? list[0];
        setForm((f) =>
          f.street || f.cityId ? f : { ...f, cityId: d.city_id, street: d.address_line },
        );
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [token]);

  // Initial load: cart + cities in parallel.
  useEffect(() => {
    if (!hydrated) return;
    loadCart();
    loadCities();
  }, [hydrated, owner, loadCart, loadCities]);

  const addressLine = useMemo(() => buildAddressLine(form), [form]);

  // Live quote: city / payment method / coupon / cart changes (debounced 300ms).
  useEffect(() => {
    if (!hydrated || form.cityId === null || cartBusy || cartLoading) {
      setQuote(null);
      setQuoteLoading(false);
      return;
    }
    let current = true;
    const controller = new AbortController();
    setQuote(null);
    setQuoteError(false);
    setQuoteLoading(true);
    const t = setTimeout(() => {
      checkoutEndpoints
        .shippingOptions(
          { city_id: form.cityId as number, payment_method: form.paymentMethod },
          getCartSession(),
          controller.signal,
        )
        .then((res) => {
          if (!current) return;
          const allowed = res.payment_methods.filter((m) =>
            ['cod', 'wallet', 'credit'].includes(m),
          );
          if (!allowed.includes(form.paymentMethod) && allowed.length) {
            setForm((f) => ({ ...f, paymentMethod: allowed[0] as CheckoutPaymentMethod }));
            return;
          }
          setQuote(res);
          setQuotedFor(quoteKey);
        })
        .catch(() => {
          if (current) setQuoteError(true);
        })
        .finally(() => {
          if (current) setQuoteLoading(false);
        });
    }, 300);
    return () => {
      current = false;
      clearTimeout(t);
      controller.abort();
    };
  }, [quoteKey, hydrated, cartBusy, cartLoading, form.cityId, form.paymentMethod]);

  // ?promo=CODE auto-applies once.
  useEffect(() => {
    if (promoTried.current || !hydrated || cartLoading || !totals) return;
    const code = params.get('promo');
    if (!code) return;
    promoTried.current = true;
    setPromoInput(code);
    applyCoupon(code).then((ok) => {
      if (!ok) {
        setBanner({
          kind: 'error',
          text: useCartStore.getState().error || 'كود الخصم غير صالح أو منتهي.',
        });
      }
    });
  }, [params, hydrated, cartLoading, totals, applyCoupon]);

  const errors = useMemo(() => {
    const e: Partial<Record<keyof CheckoutForm, string>> = {};
    if (!form.fullName.trim()) e.fullName = 'الاسم مطلوب';
    if (!form.phone.trim()) e.phone = 'رقم الموبايل مطلوب';
    else if (!/^[0-9+\s().-]{7,}$/.test(form.phone.trim())) e.phone = 'رقم موبايل غير صالح';
    if (form.email.trim() && !/^\S+@\S+\.\S+$/.test(form.email.trim())) {
      e.email = 'بريد إلكتروني غير صالح';
    }
    if (form.cityId === null) e.cityId = 'اختار المدينة';
    if (!form.street.trim()) e.street = 'العنوان مطلوب';
    if (!form.agreeTerms) e.agreeTerms = 'لازم توافق على الشروط عشان تكمل الطلب';
    return e;
  }, [form]);

  const visibleErrors = useMemo(() => {
    const out: typeof errors = {};
    (Object.keys({ ...errors, ...fieldErrors }) as (keyof CheckoutForm)[]).forEach((k) => {
      if (touched[k] || fieldErrors[k]) out[k] = fieldErrors[k] ?? errors[k];
    });
    return out;
  }, [errors, touched, fieldErrors]);

  const validate = useCallback(() => {
    setTouched({
      fullName: true,
      phone: true,
      email: true,
      cityId: true,
      street: true,
      agreeTerms: true,
    });
    if (Object.keys(errors).length > 0) {
      requestAnimationFrame(() =>
        document.querySelector<HTMLElement>('[data-checkout-error="true"]')?.focus(),
      );
      return false;
    }
    return true;
  }, [errors]);

  const handleError = useCallback(
    (err: unknown) => {
      const status = (
        err as {
          response?: {
            status?: number;
            headers?: Record<string, string>;
            data?: { message?: string; errors?: Record<string, string[]>; code?: string };
          };
        }
      )?.response;
      const code = status?.data?.code;
      if (code === 'price_changed') {
        loadCart();
        setQuote(null);
        refreshQuote((v) => v + 1);
        setBanner({
          kind: 'error',
          text: 'الأسعار اتحدثت، راجع الإجمالي واضغط إتمام الطلب تاني.',
        });
        return;
      }
      if (code === 'coupon_invalid') {
        setBanner({
          kind: 'error',
          text: status?.data?.message || 'الكوبون مبقاش صالح.',
          action: {
            label: 'إزالة الكوبون',
            onClick: () => {
              removeCoupon();
              setBanner(null);
            },
          },
        });
        return;
      }
      if (status?.status === 429) {
        const retry = status.headers?.['retry-after'] ?? 60;
        setBanner({ kind: 'error', text: `محاولات كتيرة، حاول بعد ${retry} ثانية.` });
        return;
      }
      if (status?.status === 422) {
        const apiErrors = status.data?.errors ?? {};
        const mapped: Partial<Record<keyof CheckoutForm, string>> = {};
        Object.entries(apiErrors).forEach(([key, messages]) => {
          const target = fieldMap[key];
          if (target && target !== 'banner' && messages?.[0]) mapped[target] = messages[0];
        });
        if (Object.keys(mapped).length > 0) {
          setFieldErrors(mapped);
          setTouched((t) => ({
            ...t,
            ...Object.fromEntries(Object.keys(mapped).map((k) => [k, true])),
          }));
          setTimeout(
            () =>
              document
                .querySelector('[data-checkout-error="true"]')
                ?.scrollIntoView({ behavior: 'smooth', block: 'center' }),
            50,
          );
          return;
        }
      }
      setBanner({ kind: 'error', text: status?.data?.message || 'حصل خطأ، حاول تاني.' });
    },
    [loadCart, removeCoupon],
  );

  const submit = useCallback(async () => {
    if (submittingRef.current) return;
    if (
      !pending.current &&
      (!validate() || !liveQuote?.preview || cartBusy || cartLoading || items.length === 0)
    ) {
      return;
    }
    if (!pending.current && items.some((i) => !i.priced || i.quantity > i.available)) {
      setBanner({
        kind: 'error',
        text: 'في منتجات مش متاحة بالكمية دي، شيلها أو قلل الكمية الأول.',
      });
      return;
    }
    if (
      !pending.current &&
      (form.cityId === null || !liveQuote?.payment_methods.includes(form.paymentMethod))
    ) {
      return;
    }
    submittingRef.current = true;
    setSubmitting(true);
    try {
      pending.current ??= {
        body: {
          recipient_name: form.fullName.trim(),
          recipient_phone: form.phone.trim(),
          recipient_email: form.email.trim() || undefined,
          city_id: form.cityId!,
          shipping_address_line: addressLine,
          shipping_notes: form.notes.trim() || undefined,
          payment_method: form.paymentMethod,
          expected_total: liveQuote!.preview!.grand_total,
        },
        session: getCartSession(),
      };
      try {
        sessionStorage.setItem(
          'pending_checkout',
          JSON.stringify({ owner: attemptOwner, key: keyRef.current, ...pending.current }),
        );
      } catch {
        /* In-memory retry still uses the same key. */
      }
      const res = await checkoutEndpoints.place(
        pending.current.body,
        pending.current.session,
        keyRef.current,
      );
      try {
        sessionStorage.setItem('last_order', JSON.stringify(res.data));
      } catch {
        /* Receipt storage must never turn a successful order into a retry. */
      }
      pending.current = null;
      try {
        sessionStorage.removeItem('pending_checkout');
      } catch {
        /* Optional storage. */
      }
      setUncertain(false);
      await loadCart();
      router.replace(`/checkout/success?order=${encodeURIComponent(res.data.order_number)}`);
    } catch (e) {
      handleError(e);
      const status = (e as { response?: { status: number } }).response?.status;
      // Network errors and server failures may have committed the order. Retry the exact request and key.
      if (
        status &&
        status >= 400 &&
        status < 500 &&
        status !== 408 &&
        status !== 409 &&
        status !== 429
      ) {
        pending.current = null;
        try {
          sessionStorage.removeItem('pending_checkout');
        } catch {
          /* Optional storage. */
        }
        setUncertain(false);
        keyRef.current = crypto.randomUUID();
      } else {
        setUncertain(true);
        const retryAfter = (e as { response?: { headers?: Record<string, string> } }).response
          ?.headers?.['retry-after'];
        setBanner({
          kind: 'error',
          text:
            'تعذّر تأكيد نتيجة الطلب. اضغط إعادة المحاولة لتأكيد نفس الطلب بدون تكراره.' +
            (status === 429 ? ` محاولات كتيرة، حاول بعد ${retryAfter ?? 60} ثانية.` : ''),
        });
      }
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  }, [
    validate,
    items,
    form,
    addressLine,
    liveQuote,
    cartBusy,
    cartLoading,
    loadCart,
    router,
    handleError,
    attemptOwner,
  ]);

  const step =
    !form.fullName.trim() || !form.phone.trim()
      ? 1
      : form.cityId === null || !form.street.trim()
        ? 2
        : 3;

  return {
    form,
    set,
    blur,
    visibleErrors,
    fieldErrors,
    banner,
    setBanner,
    cities,
    citiesError,
    loadCities,
    savedAddresses,
    applySavedAddress,
    quote: liveQuote,
    quoteLoading,
    quoteError,
    retryQuote: () => refreshQuote((v) => v + 1),
    locked: submitting || uncertain,
    canSubmit:
      !submitting &&
      (uncertain ||
        (!!liveQuote?.preview &&
          !!liveQuote.options.length &&
          liveQuote.payment_methods.includes(form.paymentMethod) &&
          !cartBusy &&
          !cartLoading &&
          items.length > 0)),
    uncertain,
    promoInput,
    setPromoInput,
    items,
    cartLoading,
    cartBusy,
    cartError,
    totals,
    couponCode,
    updateQuantity,
    removeItem,
    applyCoupon,
    removeCoupon,
    loadCart,
    submit,
    submitting,
    step,
    addressLine,
    user,
    token,
  };
}

export type CheckoutVM = ReturnType<typeof useCheckout>;