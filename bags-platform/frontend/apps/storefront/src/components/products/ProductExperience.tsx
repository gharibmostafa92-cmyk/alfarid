'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { ArrowLeft, ArrowRight, Check, ChevronDown, RotateCcw, Ruler, ShieldCheck, ShoppingBag, Truck } from 'lucide-react';
import { formatStorePrice } from '@alfarid/utils';
import type { StoreProduct } from '@alfarid/api-client';
import { toDetailVM } from '@/lib/catalog/catalog-mappers';
import { initialProductSelection } from '@/lib/catalog/product-selection';
import { useCartStore } from '@/stores/cart-store';
import { ShowcaseStage } from '@/components/design/ShowcaseStage';
import { useStoreLang } from '@/providers/store-lang-provider';
import { trackEcommerce } from '@/lib/analytics/ecommerce';

const formatSizeDisplay = (value: string) =>
  value.endsWith('/') ? `${value.slice(0, -1)}"` : value;

export function ProductExperience({
  product,
  onBack,
  initialColor,
  initialSize,
  initialVariantId,
  purchasingReady = true,
  headingName,
  description,
  variantLinks = false,
}: {
  product: StoreProduct;
  onBack: () => void;
  initialColor?: string | null;
  initialSize?: string | null;
  initialVariantId?: string | null;
  purchasingReady?: boolean;
  headingName?: string;
  description?: string | null;
  variantLinks?: boolean;
}) {
  const { lang, isAr } = useStoreLang();
  const detail = toDetailVM(product, lang);
  const [selection, setSelection] = useState(() => {
    const selected = initialVariantId
      ? detail.variants.find((item) => String(item.id) === initialVariantId)
      : undefined;
    return selected
      ? { size: selected.sizeLabel, color: selected.colorKey }
      : initialProductSelection(detail, initialColor, initialSize);
  });
  const { size, color } = selection;
  const [explicitVariantId, setExplicitVariantId] = useState(initialVariantId ?? null);
  const [quantity, setQuantity] = useState(1);
  const [activeImage, setActiveImage] = useState<string | null>(null);
  const [adding, setAdding] = useState(false);
  const [added, setAdded] = useState(false);
  const [notice, setNotice] = useState('');
  const addItem = useCartStore((state) => state.addItem);

  const sizeReady = detail.sizes.length === 0 || size !== null;
  const complete = sizeReady && color !== null;
  const soldOut = !detail.variants.some((item) => item.inStock && item.price !== null);
  const variant = complete
    ? detail.variants.find(
        (item) => String(item.id) === explicitVariantId && item.colorKey === color && item.sizeLabel === size
      ) ?? detail.variants.find((item) => item.colorKey === color && item.sizeLabel === size) ?? null
    : null;
  const preview =
    variant ??
    detail.variants.find(
      (item) => (!color || item.colorKey === color) && (!size || item.sizeLabel === size) && item.inStock
    ) ??
    detail.variants[0];

  const maxQty = variant?.maxQty ?? 0;
  const selectedQty = Math.min(quantity, maxQty);
  const canBuy = purchasingReady && !!variant?.inStock && variant.price !== null && maxQty >= 1;
  const price = variant ? variant.price : preview?.price ?? detail.fallbackPrice;
  const oldPrice = variant?.oldPrice ?? preview?.oldPrice ?? null;

  useEffect(() => {
    if (!purchasingReady) return;
    trackEcommerce('view_item', {
      currency: 'EGP',
      ...(price !== null ? { value: price } : {}),
      items: [
        {
          item_id: String(variant?.id ?? product.id),
          item_name: detail.name,
          ...(price !== null ? { price } : {}),
          quantity: 1,
        },
      ],
    });
  }, [purchasingReady, product.id, variant?.id, detail.name, price]);

  const candidates = detail.variants.filter((item) => !size || item.sizeLabel === size);
  const rawSlides = [
    ...new Map(
      candidates
        .flatMap((item) =>
          (item.images.length ? item.images : detail.images).map((photo) => ({
            ...photo,
            key: `${item.colorKey}|${photo.url}`,
            colorKey: item.colorKey,
            colorLabel: item.colorLabel,
          }))
        )
        .map((photo) => [photo.url, photo])
    ).values(),
  ];

  const slides =
    rawSlides.length > 0
      ? rawSlides
      : product.image
        ? [
            {
              key: `default|${product.image}`,
              url: product.image,
              thumb: product.image,
              colorKey: color ?? '',
              colorLabel: '',
            },
          ]
        : [];

  const selectedIndex = slides.findIndex(
    (photo) => photo.key === activeImage && (!color || photo.colorKey === color)
  );
  const colorIndex = slides.findIndex((photo) => photo.colorKey === (color ?? preview?.colorKey));
  const index = selectedIndex >= 0 ? selectedIndex : Math.max(0, colorIndex);
  const activeSlide = slides[index] ?? null;

  const stageSlides = slides.map((photo) => ({
    key: photo.key,
    url: photo.url,
    alt: detail.name + (photo.colorLabel ? ` — ${photo.colorLabel}` : ''),
  }));

  const resetFeedback = () => {
    setActiveImage(null);
    setAdded(false);
    setNotice('');
  };

  const chooseSize = (nextSize: string) => {
    setExplicitVariantId(null);
    const available = detail.variants.filter(
      (item) => item.sizeLabel === nextSize && item.inStock && item.price !== null
    );
    const colors = [...new Set(available.map((item) => item.colorKey))];
    setSelection({
      size: nextSize,
      color: color && colors.includes(color) ? color : colors.length === 1 ? colors[0] : color,
    });
    setQuantity(1);
    resetFeedback();
  };

  const chooseColor = (nextColor: string) => {
    setExplicitVariantId(null);
    const availableSizes = [
      ...new Set(
        detail.variants
          .filter((item) => item.colorKey === nextColor && item.inStock && item.price !== null)
          .map((item) => item.sizeLabel)
          .filter((s): s is string => !!s)
      ),
    ];
    setSelection((state) => ({
      size:
        state.size && detail.variants.some((v) => v.colorKey === nextColor && v.sizeLabel === state.size && v.inStock)
          ? state.size
          : availableSizes.length === 1
            ? availableSizes[0]
            : state.size,
      color: nextColor,
    }));
    setQuantity(1);
    resetFeedback();
  };

  const selectStageIndex = (nextIndex: number) => {
    const photo = slides[nextIndex];
    if (!photo) return;
    setExplicitVariantId(null);
    if (photo.colorKey) {
      setSelection((state) => ({ ...state, color: photo.colorKey }));
    }
    setQuantity(1);
    setActiveImage(photo.key);
    setAdded(false);
    setNotice('');
  };

  const navigate = (direction: number) => {
    if (slides.length <= 1) return;
    const nextIndex = (index + direction + slides.length) % slides.length;
    selectStageIndex(nextIndex);
  };

  const add = async () => {
    if (!variant || !canBuy || adding) return;
    setAdding(true);
    setNotice('');
    try {
      const ok = await addItem(variant.id, selectedQty);
      if (ok && variant.price !== null) {
        trackEcommerce('add_to_cart', {
          currency: 'EGP',
          value: variant.price * selectedQty,
          items: [
            {
              item_id: String(variant.id),
              item_name: detail.name,
              price: variant.price,
              quantity: selectedQty,
            },
          ],
        });
      }
      setAdded(ok);
      setNotice(
        ok
          ? isAr
            ? 'تمت إضافة المنتج إلى السلة.'
            : `Added ${selectedQty} × ${detail.name} to your shopping bag.`
          : useCartStore.getState().error ?? ''
      );
    } finally {
      setAdding(false);
    }
  };

  const activeColorLabel =
    detail.colors.find((item) => item.key === color)?.label ?? activeSlide?.colorLabel ?? '';

  const prompt = !sizeReady
    ? isAr
      ? 'اختار المقاس'
      : 'Choose your size'
    : !color
      ? isAr
        ? 'اختار اللون'
        : 'Choose your color'
      : isAr
        ? 'اختيارك جاهز'
        : 'Your selection';

  const selectionSummary = [
    size ? formatSizeDisplay(size) : null,
    activeColorLabel || null,
  ]
    .filter(Boolean)
    .join(' · ');

  return (
    <main id="main-content" tabIndex={-1} className="design-page product-experience product-mode">
      <section className="product-purchase-stage" aria-label="Product selection and 3D stage">
        <aside id="product-options" className="design-sidebar product-configurator" aria-label="Product options">
          <div className="product-configurator-main">
            <button type="button" className="product-back" onClick={onBack}>
              {isAr ? <ArrowRight size={15} /> : <ArrowLeft size={15} />}
              <span>{isAr ? 'العودة للتشكيلة' : 'Back to collection'}</span>
            </button>

            <div className="design-sidebar-tag">
              {isAr ? 'تخصيص القطعة' : 'CONFIGURE YOUR PIECE'}
            </div>
            <h2 className="product-selection-prompt" aria-live="polite">
              {prompt}
            </h2>

            {initialColor &&
              color === detail.colors.find((option) => option.key === initialColor.trim().toLowerCase())?.key && (
                <p className="product-filter-selection">
                  {isAr ? 'اللون المختار من الفلتر' : 'Color selected from your filters'}
                </p>
              )}

            {detail.sizes.length > 0 && (
              <fieldset className="product-option">
                <legend>{isAr ? 'المقاس / السعة' : 'Size / volume'}</legend>
                <div className="product-size-options">
                  {detail.sizes.map((option) => {
                    const available = detail.variants.some(
                      (item) => item.sizeLabel === option && item.inStock && item.price !== null
                    );
                    return (
                      <button
                        key={option}
                        type="button"
                        aria-pressed={size === option}
                        disabled={!available}
                        onClick={() => chooseSize(option)}
                      >
                        {formatSizeDisplay(option)}
                      </button>
                    );
                  })}
                </div>
              </fieldset>
            )}

            {detail.colors.length > 0 && (
              <fieldset className="product-option">
                <legend className="product-color-legend">
                  <span>{isAr ? 'اللون' : 'Color'}</span>
                  {activeColorLabel && <strong>{activeColorLabel}</strong>}
                </legend>
                <div className="product-color-options">
                  {detail.colors.map((option) => {
                    const available = detail.variants.some(
                      (item) =>
                        item.colorKey === option.key &&
                        (!size || item.sizeLabel === size) &&
                        item.inStock &&
                        item.price !== null
                    );
                    const isSelected = color === option.key;
                    return (
                      <button
                        key={option.key}
                        type="button"
                        aria-label={option.label}
                        aria-pressed={isSelected}
                        title={option.label}
                        disabled={!available}
                        onClick={() => chooseColor(option.key)}
                      >
                        <span
                          className="product-color-dot"
                          style={{ background: option.hex ?? '#ccc' }}
                        >
                          {isSelected && <Check size={15} strokeWidth={2.5} />}
                        </span>
                        <span>{option.label}</span>
                      </button>
                    );
                  })}
                </div>
              </fieldset>
            )}

            {complete && (
              <div className="product-option product-quantity">
                <label htmlFor={`product-quantity-${product.id}`}>
                  {isAr ? 'الكمية' : 'Quantity'}
                </label>
                <div className="product-quantity-control">
                  <button
                    type="button"
                    aria-label={isAr ? 'تقليل الكمية' : 'Decrease quantity'}
                    disabled={selectedQty <= 1}
                    onClick={() => {
                      setQuantity(selectedQty - 1);
                      setAdded(false);
                    }}
                  >
                    −
                  </button>
                  <input
                    id={`product-quantity-${product.id}`}
                    aria-label={isAr ? 'الكمية' : 'Quantity'}
                    type="number"
                    min={1}
                    max={maxQty}
                    disabled={!canBuy}
                    value={selectedQty}
                    onChange={(event) => {
                      setQuantity(Math.max(1, Math.min(maxQty, Math.floor(Number(event.target.value) || 1))));
                      setAdded(false);
                    }}
                  />
                  <button
                    type="button"
                    aria-label={isAr ? 'زيادة الكمية' : 'Increase quantity'}
                    disabled={!canBuy || selectedQty >= maxQty}
                    onClick={() => {
                      setQuantity(selectedQty + 1);
                      setAdded(false);
                    }}
                  >
                    +
                  </button>
                </div>
              </div>
            )}

            <button
              type="button"
              className="design-btn-pill product-add-button"
              disabled={!canBuy || adding}
              onClick={add}
            >
              {added ? <Check size={16} /> : <ShoppingBag size={16} />}
              <span>
                {!purchasingReady
                  ? isAr
                    ? 'جاري التحميل…'
                    : 'Loading…'
                  : soldOut
                    ? isAr
                      ? 'نفدت الكمية'
                      : 'Sold out'
                    : !complete
                      ? !sizeReady
                        ? isAr
                          ? 'اختار المقاس'
                          : 'Choose size'
                        : isAr
                          ? 'اختار اللون'
                          : 'Choose color'
                      : adding
                        ? isAr
                          ? 'جاري الإضافة…'
                          : 'Adding…'
                        : added
                          ? isAr
                            ? 'تمت الإضافة للسلة'
                            : 'Added to cart'
                          : !canBuy
                            ? isAr
                              ? 'نفدت الكمية'
                              : 'Sold out'
                            : isAr
                              ? 'أضف للسلة'
                              : 'Add to cart'}
              </span>
            </button>

            <p className="product-cart-notice" role="status">
              {notice}
            </p>

            {variant && (
              <p className="product-variant-sku" dir="ltr">
                SKU: {variant.sku}
              </p>
            )}
          </div>

          <div className="product-configurator-footer">
            {(detail.warrantyMonths || detail.material) && (
              <div className="product-quick-specs">
                {detail.material && (
                  <span className="product-quick-spec">
                    <ShieldCheck size={14} />
                    <span>{detail.material}</span>
                  </span>
                )}
                {detail.warrantyMonths && (
                  <span className="product-quick-spec">
                    <Check size={14} />
                    <span>{isAr ? `ضمان ${detail.warrantyMonths} شهر` : `${detail.warrantyMonths}-mo warranty`}</span>
                  </span>
                )}
              </div>
            )}

            <nav className="product-trust-list" aria-label={isAr ? 'روابط المساعدة' : 'Helpful links'}>
              <Link href="/legal/shipping-delivery" className="product-trust-item">
                <Truck size={15} />
                <span>{isAr ? 'الشحن والتوصيل السريع' : 'Shipping & delivery'}</span>
              </Link>
              <Link href="/legal/returns-refunds-exchanges" className="product-trust-item">
                <RotateCcw size={15} />
                <span>{isAr ? 'الاسترجاع والاستبدال المرن' : 'Returns & exchanges'}</span>
              </Link>
              <Link href="/guides/laptop-bag-fit" className="product-trust-item">
                <Ruler size={15} />
                <span>{isAr ? 'دليل المقاسات والسعة' : 'Size & fit guide'}</span>
              </Link>
            </nav>
          </div>
        </aside>

        <div className="design-carousel-view product-visual">
          <ShowcaseStage
            slides={stageSlides}
            activeIndex={index}
            canNavigate={slides.length > 1}
            onNavigate={navigate}
            onSelectIndex={selectStageIndex}
          />

          <div className="design-carousel-info product-heading">
            <p className="product-category-eyebrow">
              {detail.categoryLabel || (isAr ? 'التشكيلة' : 'The collection')}
            </p>
            <h1 className="product-title">{headingName || detail.name}</h1>

            <div className="product-price-row">
              <span className="product-price-current">
                {price !== null ? formatStorePrice(price) : isAr ? 'غير متاح' : 'Unavailable'}
              </span>
              {oldPrice !== null && price !== null && oldPrice > price && (
                <span className="product-price-old">{formatStorePrice(oldPrice)}</span>
              )}
              {selectionSummary && <span className="product-stage-chip">{selectionSummary}</span>}
            </div>

            <a
              className="design-btn-pill product-details-link"
              href={complete ? '#product-details' : '#product-options'}
            >
              <span>
                {complete
                  ? isAr
                    ? 'تفاصيل المنتج'
                    : 'Explore details'
                  : prompt}
              </span>
              <ChevronDown size={15} />
            </a>
          </div>
        </div>
      </section>

      <section id="product-details" className="product-fine-details" aria-labelledby="product-details-title">
        <div className="product-details-story">
          <h2 id="product-details-title">{isAr ? 'تفاصيل المنتج' : 'A closer look'}</h2>
          <p className="product-details-description">
            {description ||
              detail.desc ||
              (isAr ? 'تفاصيل المنتج هتكون متاحة قريبًا.' : 'More product details will be available soon.')}
          </p>

          {variantLinks && detail.variants.length > 1 && (
            <nav className="product-variants-nav" aria-label={isAr ? 'خيارات المنتج' : 'Product options'}>
              <h3>{isAr ? 'خيارات المنتج المتاحة' : 'Available options'}</h3>
              <ul className="product-variants-grid">
                {detail.variants.map((item) => {
                  const isCurrent = variant?.id === item.id;
                  return (
                    <li key={item.id}>
                      <Link
                        href={`/products/${encodeURIComponent(product.slug)}?variant=${item.id}`}
                        className={`product-variant-chip${isCurrent ? ' is-current' : ''}${!item.inStock ? ' is-out' : ''}`}
                      >
                        <span
                          className="product-variant-chip-dot"
                          style={{ background: item.hex ?? '#cbd5e1' }}
                          aria-hidden="true"
                        />
                        <span className="product-variant-chip-label">
                          {[
                            item.colorLabel,
                            item.sizeLabel ? formatSizeDisplay(item.sizeLabel) : null,
                          ]
                            .filter(Boolean)
                            .join(' · ')}
                        </span>
                        <span className="product-variant-chip-meta">
                          {item.price !== null ? formatStorePrice(item.price) : null}
                          {!item.inStock ? (isAr ? ' · نفدت الكمية' : ' · Sold out') : null}
                        </span>
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </nav>
          )}
        </div>

        <div className="product-details-specs">
          <h3>{isAr ? 'المواصفات الفنية' : 'Specifications'}</h3>
          <dl>
            {detail.material && (
              <div>
                <dt>{isAr ? 'الخامة والتصنيع' : 'Material & craftsmanship'}</dt>
                <dd>{detail.material}</dd>
              </div>
            )}
            {product.dimensions &&
              [
                product.dimensions.length_cm,
                product.dimensions.width_cm,
                product.dimensions.height_cm,
              ].some((value) => value != null) && (
                <div>
                  <dt>{isAr ? 'الأبعاد' : 'Dimensions'}</dt>
                  <dd>
                    {[
                      [isAr ? 'الطول' : 'Length', product.dimensions.length_cm],
                      [isAr ? 'العرض' : 'Width', product.dimensions.width_cm],
                      [isAr ? 'الارتفاع' : 'Height', product.dimensions.height_cm],
                    ]
                      .filter(([, value]) => value != null)
                      .map(([label, value]) =>
                        isAr ? `${label}: ${value} سم` : `${label}: ${value} cm`
                      )
                      .join(' · ')}
                  </dd>
                </div>
              )}
            {product.dimensions?.weight_kg != null && (
              <div>
                <dt>{isAr ? 'الوزن' : 'Weight'}</dt>
                <dd>
                  {isAr
                    ? `${product.dimensions.weight_kg} كجم`
                    : `${product.dimensions.weight_kg} kg`}
                </dd>
              </div>
            )}
            {(product.warranty_details || detail.warrantyMonths) && (
              <div>
                <dt>{isAr ? 'الضمان' : 'Warranty'}</dt>
                <dd>
                  {product.warranty_details ||
                    (isAr
                      ? `ضمان لمدة ${detail.warrantyMonths} شهر`
                      : `${detail.warrantyMonths}-month warranty.`)}
                </dd>
              </div>
            )}
            {product.country_of_origin && (
              <div>
                <dt>{isAr ? 'بلد المنشأ' : 'Country of origin'}</dt>
                <dd>{product.country_of_origin}</dd>
              </div>
            )}
            <div>
              <dt>{isAr ? 'القطعة المختارة' : 'Selected piece'}</dt>
              <dd>
                {[
                  variant?.sizeLabel ? formatSizeDisplay(variant.sizeLabel) : null,
                  variant?.colorLabel,
                  variant?.sku,
                ]
                  .filter(Boolean)
                  .join(' · ') || detail.name}
              </dd>
            </div>
          </dl>
        </div>
      </section>
    </main>
  );
}
