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
import { viewerKey } from '@/lib/core/viewer-key';
import { rememberGuestOrder } from '@/lib/orders/guest-orders';
import { normalizePhone } from '@/services/phone-format';
import { cartFingerprint, pendingOwnerMatches, resolveAttemptOwner } from './pending-checkout';
import { lastOrderReceipt } from './last-order-receipt';

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

export function normalizeCheckoutPhone(value: string) {
  return normalizePhone(value);
}

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

export function applySavedAddressFields(form: CheckoutForm, address: SavedAddressItem): CheckoutForm {
  return {
    ...form,
    cityId: address.city_id,
    street: address.address_line,
    building: '',
    floor: '',
    apartment: '',
  };
}

export function checkoutShippingLengthErrors(
  addressLine: string,
  notes: string,
): Partial<Pick<CheckoutForm, 'street' | 'notes'>> {
  const errors: Partial<Pick<CheckoutForm, 'street' | 'notes'>> = {};
  if (addressLine.length > 500) errors.street = 'العنوان أطول من الحد المسموح';
  if (notes.length > 500) errors.notes = 'ملاحظات الشحن أطول من الحد المسموح';
  return errors;
}

/**
 * Generate a unique idempotency key with fallback for non-secure HTTP contexts.
 */
export function newIdempotencyKey(): string {
  // Web Crypto randomUUID requires a secure origin; fallback ensures reliability on local networks.
  const generator = globalThis.crypto?.randomUUID;
  if (typeof generator === 'function') {
    return generator.call(globalThis.crypto);
  }
  return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

/** Checkout can submit only these methods. Anything else (card, bitcoin) is dropped. */
export function checkoutPaymentMethods(methods: readonly string[]): CheckoutPaymentMethod[] {
  return methods.filter((method): method is CheckoutPaymentMethod =>
    (['cod', 'wallet', 'bank_transfer', 'credit'] as const).includes(method as CheckoutPaymentMethod));
}

/**
 * Display a quote only when it strictly matches the current live cart state and selections.
 *
 * Guards against displaying stale delivery rates while background cart mutations are in flight.
 */
export function shownQuote<T>(quote: T | null, quotedFor: string, quoteKey: string, cartBusy: boolean, cartLoading: boolean): T | null {
  return quotedFor === quoteKey && !cartBusy && !cartLoading ? quote : null;
}

const PENDING_CHECKOUT_KEY = 'pending_checkout';
const PENDING_CHECKOUT_TTL_MS = 15 * 60 * 1000;
const UNSUPPORTED_PAYMENT_BANNER = 'وسائل الدفع المتاحة لهذه الوجهة غير مدعومة حالياً في هذه الواجهة.';

function readPendingRaw(): string | null {
  try {
    if (typeof localStorage !== 'undefined') {
      const value = localStorage.getItem(PENDING_CHECKOUT_KEY);
      if (value !== null) return value;
    }
  } catch { /* Storage is optional. */ }
  try {
    if (typeof sessionStorage !== 'undefined') {
      return sessionStorage.getItem(PENDING_CHECKOUT_KEY);
    }
  } catch { /* Storage is optional. */ }
  return null;
}

function writePendingRaw(value: string): void {
  let wroteLocal = false;
  try {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(PENDING_CHECKOUT_KEY, value);
      wroteLocal = true;
    }
  } catch { /* Fall back to sessionStorage when localStorage is blocked. */ }
  if (!wroteLocal) {
    try {
      if (typeof sessionStorage !== 'undefined') sessionStorage.setItem(PENDING_CHECKOUT_KEY, value);
    } catch { /* In-memory retry remains safe. */ }
  }
}

function clearPendingStorage(): void {
  try {
    if (typeof localStorage !== 'undefined') localStorage.removeItem(PENDING_CHECKOUT_KEY);
  } catch { /* Optional storage. */ }
  try {
    if (typeof sessionStorage !== 'undefined') sessionStorage.removeItem(PENDING_CHECKOUT_KEY);
  } catch { /* Optional storage. */ }
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
  const keyRef = useRef<string>(newIdempotencyKey());
  const promoTried = useRef(false);
  const [deliveryKey, setDeliveryKey] = useState<string | null>(null);

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
  const actorType = useAuthStore((s) => s.actorType);
  const hydrated = useAuthHydrated();
  const owner = viewerKey(token);
  const attemptOwner = resolveAttemptOwner(token, actorType, user?.id);
  const quotePhone = normalizeCheckoutPhone(form.phone);
  const recipientPhone = !token && /^\+?[0-9]{7,15}$/.test(quotePhone) ? quotePhone : undefined;
  const quoteKey = JSON.stringify([
    owner,
    form.cityId,
    form.paymentMethod,
    couponCode,
    items,
    quoteRevision,
    recipientPhone,
  ]);
  const liveQuote = shownQuote(quote, quotedFor, quoteKey, cartBusy, cartLoading);
  const cartSignature = cartFingerprint(items, couponCode, deliveryKey ?? '');

  // A reload after a lost response must recover the same attempt, never create a second order.
  useEffect(() => {
    if (!hydrated) return;
    pending.current = null;
    setUncertain(false);
    try {
      const saved = JSON.parse(readPendingRaw() ?? 'null');
      const expired = typeof saved?.expiresAt !== 'number' || Date.now() > saved.expiresAt;
      const savedDelivery = typeof saved?.deliveryKey === 'string' ? saved.deliveryKey : '';
      const comparable = cartFingerprint(
        items,
        couponCode,
        deliveryKey === null ? savedDelivery : deliveryKey,
      );
      const mismatchedCart =
        items.length > 0 &&
        typeof saved?.fingerprint === 'string' &&
        saved.fingerprint !== comparable;
      if (saved && (expired || mismatchedCart)) {
        clearPendingStorage();
        return;
      }
      if (saved && typeof saved.owner === 'string' && attemptOwner && saved.owner !== attemptOwner) {
        clearPendingStorage();
        return;
      }
      const ownerMatches = pendingOwnerMatches(saved?.owner, attemptOwner);
      if (
        ownerMatches &&
        typeof saved.key === 'string' &&
        saved.body?.city_id &&
        saved.body?.recipient_phone &&
        Number.isFinite(saved.body.expected_total)
      ) {
        keyRef.current = saved.key;
        pending.current = { body: saved.body, session: saved.session ?? null };
        if (deliveryKey === null && savedDelivery) setDeliveryKey(savedDelivery);
        setUncertain(true);
        setBanner({
          kind: 'info',
          text: 'في محاولة طلب سابقة لم يتم تأكيدها. اضغط إعادة المحاولة لتأكيد نفس الطلب.',
        });
        const rawForm =
          saved.rawForm && typeof saved.rawForm === 'object'
            ? (saved.rawForm as Partial<Pick<CheckoutForm, 'street' | 'building' | 'floor' | 'apartment'>>)
            : null;
        setForm((f) => ({
          ...f,
          fullName: saved.body.recipient_name,
          phone: saved.body.recipient_phone,
          email: saved.body.recipient_email ?? '',
          cityId: saved.body.city_id,
          street: rawForm?.street ?? saved.body.shipping_address_line,
          building: rawForm?.building ?? '',
          floor: rawForm?.floor ?? '',
          apartment: rawForm?.apartment ?? '',
          notes: saved.body.shipping_notes ?? '',
          paymentMethod: saved.body.payment_method,
          agreeTerms: true,
        }));
      }
    } catch {
      /* Storage is optional; the in-memory attempt remains safe. */
    }
  }, [hydrated, attemptOwner, cartSignature, deliveryKey, couponCode, items]);

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
    setForm((form) => applySavedAddressFields(form, addr));
    setFieldErrors((errors) => ({
      ...errors,
      cityId: undefined,
      street: undefined,
      building: undefined,
      floor: undefined,
      apartment: undefined,
    }));
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
        setForm((form) => form.street || form.cityId ? form : applySavedAddressFields(form, d));
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
          { city_id: form.cityId as number, payment_method: form.paymentMethod, recipient_phone: recipientPhone },
          getCartSession(),
          controller.signal,
        )
        .then((res) => {
          if (!current) return;
          const allowed = checkoutPaymentMethods(res.payment_methods);
          if (allowed.length === 0) {
            setQuote(res);
            setQuotedFor(quoteKey);
            setBanner({ kind: 'info', text: UNSUPPORTED_PAYMENT_BANNER });
            return;
          }
          if (!allowed.includes(form.paymentMethod)) {
            const nextMethod = allowed[0] as CheckoutPaymentMethod;
            setForm((f) => ({ ...f, paymentMethod: nextMethod }));
            setBanner((prev) =>
              prev?.kind === 'info' && prev.text === UNSUPPORTED_PAYMENT_BANNER ? null : prev,
            );
            setQuote(res);
            setQuotedFor(
              JSON.stringify([
                owner,
                form.cityId,
                nextMethod,
                couponCode,
                items,
                quoteRevision,
                recipientPhone,
              ]),
            );
            return;
          }
          setBanner((prev) =>
            prev?.kind === 'info' && prev.text === UNSUPPORTED_PAYMENT_BANNER ? null : prev,
          );
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
  }, [quoteKey, hydrated, cartBusy, cartLoading, form.cityId, form.paymentMethod, recipientPhone, owner, couponCode, items, quoteRevision]);

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
    else if (!/^\+?[0-9]{7,15}$/.test(normalizeCheckoutPhone(form.phone))) e.phone = 'رقم موبايل غير صالح';
    if (form.email.trim() && !/^\S+@\S+\.\S+$/.test(form.email.trim())) {
      e.email = 'بريد إلكتروني غير صالح';
    }
    if (form.cityId === null) e.cityId = 'اختار المدينة';
    if (!form.street.trim()) e.street = 'العنوان مطلوب';
    Object.assign(e, checkoutShippingLengthErrors(addressLine, form.notes));
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
      notes: true,
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

  const handleError = useCallback(    (err: unknown) => {
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

  /** Drop the remembered attempt so the shopper can edit the order and submit afresh. */
  const clearPending = useCallback(() => {
    pending.current = null;
    keyRef.current = newIdempotencyKey();
    clearPendingStorage();
    setUncertain(false);
  }, []);

  const startNewAttempt = useCallback(() => {
    clearPending();
    setBanner(null);
    void loadCart();
  }, [clearPending, loadCart]);

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
          recipient_phone: normalizeCheckoutPhone(form.phone),
          recipient_email: form.email.trim() || undefined,
          city_id: form.cityId!,
          shipping_address_line: addressLine,
          shipping_notes: form.notes.trim() || undefined,
          payment_method: form.paymentMethod,
          expected_total: liveQuote!.preview!.grand_total,
        },
        session: getCartSession(),
      };
      writePendingRaw(
        JSON.stringify({
          owner: attemptOwner,
          key: keyRef.current,
          expiresAt: Date.now() + PENDING_CHECKOUT_TTL_MS,
          fingerprint: cartFingerprint(items, couponCode, deliveryKey ?? ''),
          deliveryKey: deliveryKey ?? '',
          rawForm: {
            street: form.street,
            building: form.building,
            floor: form.floor,
            apartment: form.apartment,
          },
          ...pending.current,
        }),
      );
      const res = await checkoutEndpoints.place(
        pending.current.body,
        pending.current.session,
        keyRef.current,
      );
      if (attemptOwner === 'guest') rememberGuestOrder(res.data.order_number);
      try {
        const { order_number, status, payment_method, payment_status, grand_total, items, recipient_phone, instapay } = res.data;
        sessionStorage.setItem('last_order', JSON.stringify(lastOrderReceipt({
          order_number, status, payment_method, payment_status, grand_total, items,
          recipient_phone, instapay,
        }, attemptOwner)));
      } catch {
        /* Receipt storage must never turn a successful order into a retry. */
      }
      pending.current = null;
      clearPendingStorage();
      setUncertain(false);
      router.replace(`/checkout/success?order=${encodeURIComponent(res.data.order_number)}`);
      void loadCart().catch(() => {});
    } catch (e) {
      handleError(e);
      const response = (
        e as {
          response?: {
            status?: number;
            data?: { message?: string; code?: string };
          };
        }
      )?.response;
      const status = response?.status;
      const code = response?.data?.code;
      // A 409 from the idempotency guard is a definitive rejection: the request
      // was not committed, so clear the lock and let the shopper start over.
      const definitiveConflict = status === 409 && code !== 'idempotency_in_progress';
      // Any other 4xx except the retryable 408/429 is also definitive.
      const definitiveClientError =
        status !== undefined && status >= 400 && status < 500 && status !== 408 && status !== 409 && status !== 429;

      if (status === 429) {
        // A rate-limited retry cannot resolve an earlier request that may have committed.
        const retryAfter = (e as { response?: { headers?: Record<string, string> } }).response
          ?.headers?.['retry-after'];
        setBanner({
          kind: 'error',
          text: `طلبات كثيرة في وقت قصير. يرجى الانتظار ${retryAfter ?? 60} ثانية قبل إعادة المحاولة.`,
        });
      } else if (definitiveConflict || definitiveClientError) {
        clearPending();
        // Keep the actionable banners produced by handleError (coupon removal,
        // price refresh) instead of overwriting them with a generic message.
        if (code !== 'price_changed' && code !== 'coupon_invalid') {
          void loadCart();
          setBanner({
            kind: 'error',
            text: definitiveConflict
              ? response?.data?.message ||
                'تم رفض المحاولة لأن السلة تغيّرت أو الطلب مسجّل ببيانات مختلفة. عدّل طلبك وابدأ من جديد.'
              : response?.data?.message || 'لم يتم إنشاء الطلب. راجع بياناتك وحاول تاني.',
            action: { label: 'بدء محاولة جديدة', onClick: startNewAttempt },
          });
        }
      } else {
        setUncertain(true);
        setBanner({
          kind: 'error',
          text:
            'تعذّر تأكيد نتيجة الطلب. اضغط إعادة المحاولة لتأكيد نفس الطلب بدون تكراره.',
          action: { label: 'تعديل الطلب وبدء محاولة جديدة', onClick: startNewAttempt },
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
    clearPending,
    startNewAttempt,
    couponCode,
    deliveryKey,
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
    startNewAttempt,
    submitting,
    step,
    addressLine,
    user,
    token,
    deliveryKey,
    setDeliveryKey,
  };
}

export type CheckoutVM = ReturnType<typeof useCheckout>;
