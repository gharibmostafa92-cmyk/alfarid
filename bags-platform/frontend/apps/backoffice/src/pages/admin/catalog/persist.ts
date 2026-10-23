import { productsApi, variantsApi, productMediaApi } from '@alfarid/api-client';
import { productToPayload, variantToPayload } from './mappers';

// Save each successful identity immediately, so a retry cannot create duplicate rows/files.
export async function persistProduct(state: any, original: any, canViewCost: boolean, onState: (state: any) => void, onUpload: () => void = () => {}) {
  const removeIfPresent = async (operation: () => Promise<unknown>) => {
    try { await operation(); } catch (e: any) { if (e?.response?.status !== 404) throw e; }
  };
  let next = { ...state, rows: state.rows.map((r: any) => ({ ...r })), media: { ...state.media, general: [...state.media.general], byVariant: Object.fromEntries(Object.entries(state.media.byVariant).map(([k,v]) => [k,[...(v as any[])]])) } };
  const payload = (row: any, index: number, create: boolean) => variantToPayload({ ...row, weight: row.weight ?? '', reorder: row.reorder ?? '', sort: index }, { canViewCost, isCreate: create });
  const variantRequest = async (row: any, operation: () => Promise<any>) => {
    try { return await operation(); } catch (error: any) {
      error.catalogRowKey = row._id;
      error.catalogProductId = next.id;
      throw error;
    }
  };
  if (!next.id) {
    const created = await productsApi.create({ ...productToPayload(next.basic), variants: next.rows.map((r: any,i: number) => payload(r,i,true)) });
    next = { ...next, id: created.id, code: created.code, status: created.status, rows: next.rows.map((r: any,i: number) => ({ ...r, serverId: created.variants?.[i]?.id, sku: created.variants?.[i]?.sku, barcode: created.variants?.[i]?.barcode })) };
    history.replaceState(null, '', '#/products/' + created.id);
    onState(next);
  } else {
    const createdThisRun = new Set<number>();
    await productsApi.update(next.id, productToPayload(next.basic));
    const removed = (original.rows || []).filter((r: any) => r.serverId && !next.rows.some((n: any) => n.serverId === r.serverId));
    const key = (r: any) => [r.color?.en,r.size,r.capacity].map(v => (v || '').trim().toLowerCase()).join('|');
    const conflicting = removed.filter((r: any) => next.rows.some((n: any) => n.serverId !== r.serverId && key(n) === key(r)));
    if (conflicting.length && next.status === 'published' && original.rows.filter((r: any) => r.status === 'active').length <= conflicting.filter((r: any) => r.status === 'active').length) throw new Error('انقل المنتج لمسودة أولاً قبل استبدال آخر متغير نشط.');
    for (const row of conflicting) await variantRequest(row, () => removeIfPresent(() => variantsApi.remove(next.id,row.serverId)));
    for (const [i,row] of next.rows.entries()) {
      if (row.serverId) continue;
      const saved = await variantRequest(row, () => variantsApi.create(next.id,payload(row,i,true)));
      createdThisRun.add(saved.id);
      Object.assign(row,{ serverId:saved.id,sku:saved.sku,barcode:saved.barcode }); onState({ ...next });
    }
    for (const [i,row] of next.rows.entries()) {
      if (createdThisRun.has(row.serverId)) continue;
      const old = original.rows?.find((r: any) => r.serverId === row.serverId);
      if (!old) { await variantRequest(row, () => variantsApi.update(next.id,row.serverId,payload(row,i,false))); continue; }
      const before = payload(old,original.rows.indexOf(old),false), after = payload(row,i,false);
      const diff = Object.fromEntries(Object.entries(after).filter(([k,v]) => JSON.stringify(v) !== JSON.stringify((before as any)[k])));
      if (Object.keys(diff).length) await variantRequest(row, () => variantsApi.update(next.id,row.serverId,diff));
    }
    for (const row of removed.filter((r: any) => !conflicting.includes(r))) await variantRequest(row, () => removeIfPresent(() => variantsApi.remove(next.id,row.serverId)));
  }
  onUpload();
  // Resolve color attachments against the saved variants, not a possibly stale UI row.
  // The product/variant write can succeed even when the local gallery still lacks its ID.
  const colorKey = (value: string | null | undefined) => (value || '').trim().toLowerCase();
  const colorGroups = Object.entries(next.media.byVariant) as [string, any[]][];
  const pendingColorGroups = colorGroups.filter(([, files]) => files.some(file => !file.serverId));
  const savedColorIds = new Map<string, number>();
  if (pendingColorGroups.length) {
    const savedVariants = await variantsApi.list(next.id);
    for (const row of next.rows) {
      if (row.serverId) continue;
      const saved = savedVariants.find((variant: any) =>
        colorKey(variant.color?.name_en ?? variant.color_name_en) === colorKey(row.color?.en)
        && colorKey(variant.size) === colorKey(row.size)
        && colorKey(variant.capacity) === colorKey(row.capacity));
      if (saved) Object.assign(row, { serverId: saved.id, sku: saved.sku, barcode: saved.barcode });
    }
    onState({ ...next });
    const byId = new Map(savedVariants.map((variant: any) => [variant.id, variant]));
    // Preserve display order when multiple sizes share a color.
    const ordered = [
      ...next.rows.map((row: any) => byId.get(row.serverId)).filter(Boolean),
      ...savedVariants,
    ];
    for (const variant of ordered as any[]) {
      const color = colorKey(variant.color?.name_en ?? variant.color_name_en);
      if (color && !savedColorIds.has(color)) savedColorIds.set(color, variant.id);
    }
    for (const [color, files] of pendingColorGroups) {
      if (!savedColorIds.has(colorKey(color))) {
        const message = `تعذّر ربط صور اللون «${color}»: لا توجد نسخة محفوظة بهذا اللون. الصور المختارة محفوظة في الصفحة؛ راجع لون النسخة في تبويب المتغيرات والأسعار.`;
        for (const file of files.filter(file => !file.serverId)) {
          file.status = 'failed'; file.message = message;
        }
        onState({ ...next });
        throw Object.assign(new Error(message), { catalogTab: 'media' });
      }
    }
  }
  const all = (media: any) => [...media.general,...Object.values(media.byVariant).flat(),...(media.video ? [media.video] : [])];
  for (const old of all(original.media || { general:[],byVariant:{} }) as any[]) {
    if (old.serverId && !all(next.media).some((f: any) => f.serverId === old.serverId)) {
      try { await removeIfPresent(() => productMediaApi.remove(next.id,old.serverId)); }
      catch (e) {
        // Roll back only the rejected removal; retain successful uploads for retry.
        if (old === original.media.video) next.media.video = old;
        else if (original.media.general.includes(old)) next.media.general.splice(original.media.general.indexOf(old),0,old);
        else for (const [color,items] of Object.entries(original.media.byVariant) as [string,any[]][]) if (items.includes(old)) next.media.byVariant[color] = [...(next.media.byVariant[color] || []),old];
        onState({ ...next }); throw e;
      }
    }
  }
  const groups: [string,any[]][] = [['',next.media.general],...Object.entries(next.media.byVariant) as [string,any[]][]];
  for (const [color,files] of groups) {
    // Color images belong to the first saved variant of that color, by display order.
    const variantId = color ? savedColorIds.get(colorKey(color)) : null;
    const pending = files.filter(file => !file.serverId);
    let uploadError: unknown;
    const worker = async () => { while (pending.length) {
      const file = pending.shift();
      try {
        file.status='uploading'; onState({ ...next });
        const saved = await productMediaApi.uploadImages(next.id,[file.file],variantId,pct => { file.progress=pct; onState({ ...next }); });
        Object.assign(file,{ serverId:saved[0].id,variantId:variantId || undefined,status:'done',url:saved[0].url }); onState({ ...next });
      } catch(e: any) { file.status='failed'; file.message=e?.response?.data?.message || 'تعذّر رفع الملف، أعد المحاولة'; onState({ ...next }); uploadError ??= e; }
    } };
    await Promise.all([worker(),worker()]);
    if (uploadError) throw uploadError;
  }
  if (next.media.video && !next.media.video.serverId) {
    try {
      next.media.video.status='uploading';onState({ ...next });
      const saved = await productMediaApi.uploadVideo(next.id,next.media.video.file,pct => { next.media.video.progress=pct;onState({ ...next }); });
      Object.assign(next.media.video,{serverId:saved[0].id,status:'done',url:saved[0].url}); onState({ ...next });
    } catch(e: any){next.media.video.status='failed';next.media.video.message=e?.response?.data?.message || 'تعذّر رفع الفيديو';onState({ ...next });throw e;}
  }
  if (next.media.general.length) await productMediaApi.reorder(next.id,next.media.general.map((f: any) => f.serverId));
  return next;
}
