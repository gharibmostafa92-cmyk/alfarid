'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { ArrowLeft, Check, ChevronDown, ShoppingBag } from 'lucide-react';
import { formatStorePrice } from '@alfarid/utils';
import type { StoreProduct } from '@alfarid/api-client';
import { toDetailVM } from '@/lib/catalog/catalog-mappers';
import { initialProductSelection } from '@/lib/catalog/product-selection';
import { useCartStore } from '@/stores/cart-store';
import { ShowcaseStage } from '@/components/design/ShowcaseStage';
import { useStoreLang } from '@/providers/store-lang-provider';
import { trackEcommerce } from '@/lib/analytics/ecommerce';

export function ProductExperience({ product, onBack, initialColor, initialSize, initialVariantId, purchasingReady = true, headingName, description, variantLinks = false }: {
  product: StoreProduct; onBack: () => void; initialColor?: string | null; initialSize?: string | null; initialVariantId?: string | null; purchasingReady?: boolean; headingName?: string; description?: string | null; variantLinks?: boolean;
}) {
  const { lang, isAr } = useStoreLang();
  const detail = toDetailVM(product, lang);
  const [selection, setSelection] = useState(() => {
    const selected = initialVariantId ? detail.variants.find((item) => String(item.id) === initialVariantId) : undefined;
    return selected ? { size: selected.sizeLabel, color: selected.colorKey } : initialProductSelection(detail, initialColor, initialSize);
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
  const variant = complete ? detail.variants.find((item) => String(item.id) === explicitVariantId && item.colorKey === color && item.sizeLabel === size)
    ?? detail.variants.find((item) => item.colorKey === color && item.sizeLabel === size) ?? null : null;
  const preview = variant ?? detail.variants.find((item) => (!color || item.colorKey === color) && (!size || item.sizeLabel === size) && item.inStock) ?? detail.variants[0];
  const maxQty = variant?.maxQty ?? 0;
  const selectedQty = Math.min(quantity, maxQty);
  const canBuy = purchasingReady && !!variant?.inStock && variant.price !== null && maxQty >= 1;
  const price = variant ? variant.price : detail.fallbackPrice;
  useEffect(() => {
    if (!purchasingReady) return;
    trackEcommerce('view_item', { currency: 'EGP', ...(price !== null ? { value: price } : {}),
      items: [{ item_id: String(variant?.id ?? product.id), item_name: detail.name, ...(price !== null ? { price } : {}), quantity: 1 }] });
  }, [purchasingReady, product.id, variant?.id, detail.name, price]);
  const candidates = detail.variants.filter((item) => !sizeReady || item.sizeLabel === size);
  const slides = [...new Map(candidates.flatMap((item) => (item.images.length ? item.images : detail.images).map((photo) => ({ ...photo, key: item.colorKey + '|' + photo.url, colorKey: item.colorKey, colorLabel: item.colorLabel }))).map((photo) => [photo.key, photo])).values()];
  const selectedIndex = slides.findIndex((photo) => photo.key === activeImage && (!color || photo.colorKey === color));
  const colorIndex = slides.findIndex((photo) => photo.colorKey === (color ?? preview?.colorKey));
  const index = selectedIndex >= 0 ? selectedIndex : Math.max(0, colorIndex);
  const photoAt = (offset: number) => slides.length ? slides[(index + offset + slides.length) % slides.length] : null;
  const stageImage = (offset: number) => { const photo = photoAt(offset); return { url: photo?.url ?? product.image, alt: detail.name + (photo ? ' — ' + photo.colorLabel : '') }; };
  const resetFeedback = () => { setActiveImage(null); setAdded(false); setNotice(''); };
  const chooseSize = (nextSize: string) => {
    setExplicitVariantId(null);
    const available = detail.variants.filter((item) => item.sizeLabel === nextSize && item.inStock && item.price !== null);
    const colors = [...new Set(available.map((item) => item.colorKey))];
    setSelection({ size: nextSize, color: color && colors.includes(color) ? color : colors.length === 1 ? colors[0] : null });
    setQuantity(1);
    resetFeedback();
  };
  const chooseColor = (nextColor: string) => { setExplicitVariantId(null); setSelection((state) => ({ ...state, color: nextColor })); setQuantity(1); resetFeedback(); };
  const navigate = (direction: number) => { const photo = photoAt(direction); if (photo) { setExplicitVariantId(null); setSelection((state) => ({ ...state, color: photo.colorKey })); setQuantity(1); setActiveImage(photo.key); setAdded(false); setNotice(''); } };
  const add = async () => {
    if (!variant || !canBuy || adding) return;
    setAdding(true); setNotice('');
    try {
      const ok = await addItem(variant.id, selectedQty);
      if (ok && variant.price !== null) trackEcommerce('add_to_cart', { currency: 'EGP', value: variant.price * selectedQty,
        items: [{ item_id: String(variant.id), item_name: detail.name, price: variant.price, quantity: selectedQty }] });
      setAdded(ok);
      setNotice(ok ? (isAr ? 'تمت إضافة المنتج إلى السلة.' : 'Added ' + selectedQty + ' × ' + detail.name + ' to your shopping bag.') : useCartStore.getState().error ?? '');
    }
    finally { setAdding(false); }
  };
  const prompt = !sizeReady ? (isAr ? 'اختار المقاس' : 'Choose your size') : !color ? (isAr ? 'اختار اللون' : 'Choose your color') : (isAr ? 'اختيارك' : 'Your selection');

  return <main id="main-content" tabIndex={-1} className="design-page product-experience product-mode">
    <section className="product-purchase-stage" aria-label="Product selection and 3D stage">
      <aside id="product-options" className="design-sidebar product-configurator" aria-label="Product options">
        <button className="product-back" onClick={onBack}><ArrowLeft size={14} /> {isAr ? 'العودة للتشكيلة' : 'Back to collection'}</button>
        <div className="design-sidebar-tag">{isAr ? 'اختار شنطتك' : '// YOUR PIECE'}</div>
        <h2 className="product-selection-prompt" aria-live="polite">{prompt}</h2>
        {initialColor && color === detail.colors.find((option) => option.key === initialColor.trim().toLowerCase())?.key && <p className="product-filter-selection">{isAr ? 'اللون المختار من الفلتر' : 'Color selected from your filters'}</p>}
        {detail.sizes.length > 0 && <fieldset className="product-option"><legend>{isAr ? 'المقاس / السعة' : 'Size / volume'}</legend><div className="product-size-options">{detail.sizes.map((option) => <button key={option} type="button" aria-pressed={size === option} disabled={!detail.variants.some((item) => item.sizeLabel === option && item.inStock && item.price !== null)} onClick={() => chooseSize(option)}>{option}</button>)}</div></fieldset>}
        {sizeReady && <fieldset className="product-option"><legend>{isAr ? 'اللون' : 'Color'}{color && <strong>{detail.colors.find((item) => item.key === color)?.label}</strong>}</legend><div className="product-color-options">{detail.colors.map((option) => {
          const available = detail.variants.some((item) => item.colorKey === option.key && item.sizeLabel === size && item.inStock && item.price !== null);
          return <button key={option.key} type="button" aria-label={option.label} aria-pressed={color === option.key} title={option.label} disabled={!available} onClick={() => chooseColor(option.key)}><span className="product-color-dot" style={{ background: option.hex ?? '#ccc' }}>{color === option.key && <Check size={15} />}</span><span>{option.label}</span></button>;
        })}</div></fieldset>}
        {complete && <div className="product-option product-quantity"><label htmlFor={'product-quantity-' + product.id}>Quantity</label><div className="product-quantity-control"><button type="button" aria-label="Decrease quantity" disabled={selectedQty <= 1} onClick={() => { setQuantity(selectedQty - 1); setAdded(false); }}>−</button><input id={'product-quantity-' + product.id} aria-label="Quantity" type="number" min={1} max={maxQty} disabled={!canBuy} value={selectedQty} onChange={(event) => { setQuantity(Math.max(1, Math.min(maxQty, Math.floor(Number(event.target.value) || 1)))); setAdded(false); }} /><button type="button" aria-label="Increase quantity" disabled={!canBuy || selectedQty >= maxQty} onClick={() => { setQuantity(selectedQty + 1); setAdded(false); }}>+</button></div></div>}
        <button className="design-btn-pill product-add-button" disabled={!canBuy || adding} onClick={add}>{added ? <Check size={16} /> : <ShoppingBag size={16} />}{!purchasingReady ? (isAr ? 'جاري التحميل…' : 'Loading…') : soldOut ? (isAr ? 'نفدت الكمية' : 'Sold out') : !complete ? !sizeReady ? (isAr ? 'اختار المقاس' : 'Choose size') : (isAr ? 'اختار اللون' : 'Choose color') : adding ? (isAr ? 'جاري الإضافة…' : 'Adding…') : added ? (isAr ? 'تمت الإضافة للسلة' : 'Added to cart') : !canBuy ? (isAr ? 'نفدت الكمية' : 'Sold out') : (isAr ? 'أضف للسلة' : 'Add to cart')}</button>
        <p className="product-cart-notice" role="status">{notice}</p>
        {variant && <p className="product-variant-sku">SKU: {variant.sku}</p>}
        <p><Link href="/legal/shipping-delivery">الشحن والتوصيل</Link> · <Link href="/legal/returns-refunds-exchanges">الاسترجاع والاستبدال</Link> · <Link href="/guides/laptop-bag-fit">دليل قياس الشنطة واللابتوب</Link></p>
      </aside>
      <div className="design-carousel-view product-visual">
        <ShowcaseStage previous={stageImage(-1)} current={stageImage(0)} next={stageImage(1)} canNavigate={slides.length > 1} onNavigate={navigate} />
        <div className="design-carousel-info product-heading"><p className="design-brand-title">{detail.categoryLabel || (isAr ? 'التشكيلة' : 'The collection')}</p><h1 className="design-bag-subtitle">{headingName || detail.name} &nbsp;|&nbsp; {price !== null ? formatStorePrice(price) : (isAr ? 'غير متاح' : 'Unavailable')}</h1><p className="product-stage-selection">{[size, detail.colors.find((item) => item.key === color)?.label].filter(Boolean).join(' / ') || prompt}</p><a className="design-btn-pill product-details-link" href={complete ? '#product-details' : '#product-options'}>{complete ? (isAr ? 'تفاصيل المنتج' : 'Explore details') : prompt} <ChevronDown size={15} /></a></div>
        <a className="design-scroll-hint" href="#product-details"><ChevronDown size={14} /> {isAr ? 'تفاصيل المنتج' : 'Scroll to discover the details'}</a>
      </div>
    </section>
    <section id="product-details" className="product-fine-details" aria-labelledby="product-details-title">
      <div><h2 id="product-details-title">{isAr ? 'تفاصيل المنتج' : 'A closer look'}</h2><p>{description || detail.desc || (isAr ? 'تفاصيل المنتج هتكون متاحة قريبًا.' : 'More product details will be available soon.')}</p></div>
      {variantLinks && detail.variants.length > 1 && <nav aria-label="خيارات المنتج">
        <h2>خيارات المنتج</h2>
        <ul>{detail.variants.map((item) => <li key={item.id}>
          <Link href={`/products/${encodeURIComponent(product.slug)}?variant=${item.id}`}>
            {[item.colorLabel, item.sizeLabel, item.price !== null ? formatStorePrice(item.price) : null, !item.inStock ? 'نفدت الكمية' : null].filter(Boolean).join(' · ')}
          </Link>
        </li>)}</ul>
      </nav>}
      <dl>
        {detail.material && <div><dt>Material & craftsmanship</dt><dd>{detail.material}</dd></div>}
        {product.dimensions && [product.dimensions.length_cm, product.dimensions.width_cm, product.dimensions.height_cm].some((value) => value != null) && <div><dt>Dimensions</dt><dd>{[['Length', product.dimensions.length_cm], ['Width', product.dimensions.width_cm], ['Height', product.dimensions.height_cm]].filter(([, value]) => value != null).map(([label, value]) => `${label}: ${value} cm`).join(' · ')}</dd></div>}
        {product.dimensions?.weight_kg != null && <div><dt>Weight</dt><dd>{product.dimensions.weight_kg} kg</dd></div>}
        {(product.warranty_details || detail.warrantyMonths) && <div><dt>Warranty</dt><dd>{product.warranty_details || `${detail.warrantyMonths}-month warranty.`}</dd></div>}
        {product.country_of_origin && <div><dt>Country of origin</dt><dd>{product.country_of_origin}</dd></div>}
        <div><dt>Selected piece</dt><dd>{[variant?.sizeLabel, variant?.colorLabel, variant?.sku].filter(Boolean).join(' · ') || detail.name}</dd></div>
      </dl>
    </section>
  </main>;
}
