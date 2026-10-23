const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const base = process.env.BACKOFFICE_URL || 'http://localhost:5173/dashboard';

(async () => {
 const browser = await chromium.launch({headless:true,channel:'msedge'});
 try {
  const context = await browser.newContext();
  await context.addInitScript(()=>localStorage.setItem('backoffice_token','catalog-test-only'));
  const errors=[],writes=[];
  const category={id:1,name_ar:'حقائب',name_en:'Bags',parent_id:null,is_active:true,children:[]};
  const brand={id:1,name_ar:'براند تجريبي',name_en:'Test Brand',slug:'test-brand',description_ar:null,description_en:null,sort_order:0,is_active:true,logo_url:null,products_count:0};
  let product,media=[],refusePublish=true,permissions=['*'];
  await context.route('**/api/v1/admin/**',async route=>{
   const req=route.request(),path=new URL(req.url()).pathname,method=req.method();
   const ok=data=>route.fulfill({json:{data}});
   if(path.endsWith('/me'))return ok({id:1,name:'Test',is_active:true,roles:[{name:'staff'}],permissions});
   if(method!=='GET')writes.push({path,method,body:req.postData()});
   if(path.endsWith('/categories'))return ok([category]);
   if(path.endsWith('/brands')&&method==='POST')return ok(brand);
   if(path.endsWith('/brands/1')&&method==='DELETE')return route.fulfill({status:422,json:{message:'البراند مرتبط بمنتجات محذوفة',errors:[]}});
   if(path.endsWith('/brands'))return route.fulfill({json:{data:[brand],meta:{current_page:1,last_page:1,total:1,per_page:15,from:1,to:1}}});
   if(path.endsWith('/products')&&method==='POST'){
    const data=req.postDataJSON();assert.ok(!('sku' in data.variants[0]));assert.ok(!('barcode' in data.variants[0]));
    product={...data,id:5,code:'P00005',status:'draft',category,dimensions:{},variants:data.variants.map((v,i)=>({...v,id:7+i,sku:'P00005-01',barcode:'2000000000008',color:{name_ar:null,name_en:null,hex:null}}))};return ok(product);
   }
   if(path.endsWith('/media/reorder'))return ok(null);
   if(path.endsWith('/media')&&method==='POST'){
    assert.match(req.headers()['content-type'],/multipart\/form-data/);assert.match(req.postData(),/files\[\]/);
    media=[{id:11,collection:'images',variant_id:null,url:'https://example.test/image.png',thumb_url:null,order:0}];return ok(media);
   }
   if(path.endsWith('/media'))return ok(media);
   if(path.endsWith('/products/5')&&method==='PUT'){
    const data=req.postDataJSON();assert.ok(!('variants' in data));
    if(data.status==='published'&&refusePublish){refusePublish=false;return route.fulfill({status:422,json:{message:'النشر غير متاح',errors:{status:['القسم غير نشط مؤقتاً']}}});}
    product={...product,...data};return ok(product);
   }
   if(path.endsWith('/products/5'))return ok(product);
   if(path.endsWith('/products/999'))return route.fulfill({status:404,json:{message:'Not found'}});
   return route.fulfill({json:{data:[],meta:{current_page:1,last_page:1,total:0,per_page:15,from:null,to:null}}});
  });
  const page=await context.newPage();page.setDefaultTimeout(10000);page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'#/products/new',{waitUntil:'domcontentloaded'});
  await page.getByPlaceholder('مثال: شنطة سفر الفريد').fill('حقيبة اختبار');
  await page.getByPlaceholder('Alfarid Travel Bag').fill('Test Bag');
  await page.getByText('اختر القسم',{exact:true}).click();
  await page.getByText('حقائب',{exact:true}).click();
  await page.getByRole('button',{name:/المتغيرات والأسعار/}).click();
  await page.locator('input[data-cell="0-2"]').fill('125');
  await page.getByRole('button',{name:/الصور والفيديو/}).click();
  const png=await page.evaluate(()=>{const canvas=document.createElement('canvas');canvas.width=2;canvas.height=2;return canvas.toDataURL('image/png').split(',')[1];});
  await page.locator('.catalog-surface input[type="file"]').setInputFiles({name:'bag.png',mimeType:'image/png',buffer:Buffer.from(png,'base64')});
  await page.getByText('bag.png',{exact:true}).waitFor();
  await page.getByRole('button',{name:'حفظ ونشر',exact:true}).click();
  await page.getByText('القسم غير نشط مؤقتاً',{exact:true}).first().waitFor();
  assert.match(page.url(),/#\/products\/5/);
  await page.getByRole('button',{name:'فهمت',exact:true}).click();
  await page.getByRole('button',{name:'حفظ ونشر',exact:true}).click();
  await page.getByText('تم نشر المنتج',{exact:true}).waitFor();
  assert.equal(writes.filter(w=>w.path.endsWith('/products')&&w.method==='POST').length,1);
  assert.equal(writes.filter(w=>w.path.endsWith('/media')&&w.method==='POST').length,1);
  await page.getByRole('button',{name:'متابعة التعديل',exact:true}).click();
  await page.getByRole('button',{name:'إلغاء النشر',exact:true}).waitFor();
  assert.equal(await page.getByText('البراندات',{exact:true}).count(),0);
  assert.ok(writes.every(w=>!w.path.includes('/brands')));
  await page.goto(base+'#/products/999',{waitUntil:'domcontentloaded'});
  await page.getByText('المنتج غير موجود',{exact:true}).waitFor();
  permissions=['products.view','brands.view','categories.view'];
  await page.goto(base+'#/products/5',{waitUntil:'domcontentloaded'});
  await page.reload({waitUntil:'domcontentloaded'});
  await page.getByPlaceholder('Alfarid Travel Bag').waitFor();
  assert.equal(await page.getByPlaceholder('Alfarid Travel Bag').isDisabled(),true);
  assert.equal(await page.getByRole('button',{name:'حفظ التعديلات',exact:true}).count(),0);
  if(process.env.CATALOG_SCREENSHOTS)await page.screenshot({path:require('node:path').join(require('node:os').tmpdir(),'catalog-desktop.png'),fullPage:true,animations:'disabled'});
  await page.setViewportSize({width:390,height:844});
  await page.getByRole('button',{name:/المتغيرات والأسعار/}).click();
  if(process.env.CATALOG_SCREENSHOTS)await page.screenshot({path:require('node:path').join(require('node:os').tmpdir(),'catalog-mobile.png'),fullPage:true,animations:'disabled'});
  assert.deepEqual(errors,[]);
  console.log('PASS: create → upload → publish rejection → retry → publish; own products without brands, 404, readonly and mobile; no runtime errors');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
