const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = process.env.BACKOFFICE_URL || 'http://127.0.0.1:5173';
const mobile = process.env.OPERATIONS_MOBILE === 'true';
const out = path.resolve(__dirname, '../../../test-results/operations' + (mobile ? '-mobile' : ''));
const user = {id:1,name:'Operations Tester',email:'tester@example.test',roles:[{name:'super_admin'}],permissions:['orders.view','orders.update','orders.payment','orders.allocate','returns.view','returns.create','returns.update','returns.approve','returns.receive','returns.refund','treasuries.view','expenses.view','expenses.create','expenses.delete','treasury_transfers.view','pos_shifts.view','quotations.view','payment_notices.view','reports.view','shipping.view','price_lists.view','promotions.view'],branch_id:null};
const order = {id:71,order_number:'ORD-RUNTIME',status:'processing',payment_method:'bank_transfer',payment_status:'unpaid',requires_refund:false,needs_attention:false,guest_name:'عميل الاختبار',guest_phone:'01000000000',grand_total:'250.00',subtotal:'220.00',shipping_cost:'20.00',tax_amount:'0',discount_amount:'0',cod_fee:'10.00',coupon_code:null,shipping_address_line:'عنوان الاختبار الحقيقي',shipping_notes:'ملاحظة الاختبار',created_at:'2026-10-27T08:00:00Z',allocated_branch:{id:7,name:'فرع الاختبار'},items:[{id:731,product_variant_id:41,product_name:'حقيبة الاختبار',sku:'BAG-41',qty:2,unit_price:'110',line_total:'220',allocated_branch_id:7}],status_history:[]};
let ret={id:81,return_number:'RET-RUNTIME',order_id:71,order_number:order.order_number,customer_id:null,customer_name:'عميل الاختبار',branch_id:7,status:'approved',refund_method:'original_payment',total_refund_amount:'110',reason:'سبب اختبار حقيقي',admin_notes:'مراجعة الاختبار',refund_reference:null,approved_at:'2026-10-27T09:00:00Z',received_at:null,refunded_at:null,created_at:'2026-10-27T08:00:00Z',items:[{id:831,order_item_id:731,product_variant_id:41,product_name:'حقيبة الاختبار',sku:'BAG-41',requested_qty:1,approved_qty:1,received_good_qty:null,received_damaged_qty:null,unit_refund_price:'110',item_reason:'اختبار',attachments:[]}]};
const treasury={id:11,code:'TR-RUNTIME',name:'خزينة الاختبار',type:'main_safe',branch_id:7,branch:{id:7,name:'فرع الاختبار'},current_balance:'9876.50',is_active:true};
const shift={id:21,shift_number:'SH-RUNTIME',branch_id:7,user_id:1,treasury_id:11,branch:{id:7,name:'فرع الاختبار'},cashier:{id:1,name:'الكاشير الفعلي'},treasury:{id:11,name:'خزينة الاختبار'},status:'closed_pending_approval',opened_at:'2026-10-27T08:00:00Z',closed_at:'2026-10-27T16:00:00Z',opening_cash:'100',cash_sales_total:'800',card_sales_total:'500',cash_returns_total:'25',cash_expenses_total:'15',expected_closing_cash:'860',actual_closing_cash:'850',cash_difference:'-10',expenses:[]};
const quote={id:31,quotation_number:'QT-RUNTIME',customer_id:1,customer:{id:1,name:'عميل الجملة',company_name:'شركة الاختبار'},status:'requested',valid_until:null,subtotal:'0',discount_amount:'0',tax_amount:'0',grand_total:'0',customer_notes:'طلب حقيقي',admin_notes:null,converted_order_id:null,created_at:'2026-10-27T08:00:00Z',items:[{id:341,product_variant_id:41,qty:2,requested_target_price:'95',offered_unit_price:null,line_total:null,variant:{id:41,sku:'BAG-41',product_name:'حقيبة الاختبار'}}]};
const pageOf=(data,page=1,total=data.length)=>({data,meta:{current_page:page,last_page:Math.max(1,Math.ceil(total/20)),per_page:20,total,from:data.length?1:null,to:data.length||null}});
(async()=>{
 fs.mkdirSync(out,{recursive:true});const browser=await chromium.launch({headless:true,channel:'msedge'});
 try {
  const context=await browser.newContext({viewport:mobile ? {width:390,height:844} : {width:1440,height:1000}}),page=await context.newPage();page.setDefaultTimeout(12000);page.setDefaultNavigationTimeout(60000);
  const errors=[],calls=[],mutations=[];page.on('pageerror',e=>errors.push(e.message));
  let notes=[{id:'uuid-order',type:'AdminOrderCreatedNotification',data:{order_id:71,order_number:'ORD-RUNTIME',message:'إشعار طلب حقيقي'},read_at:null,created_at:'2026-10-27T08:00:00Z'}], failRead=false,failOrder=false;
  let expense={id:51,expense_number:'EXP-RUNTIME',branch_id:7,branch:{name:'فرع الاختبار'},treasury_id:11,category:'صيانة',amount:'125.50',expense_date:'2026-10-28',shift_id:null,creator:{name:'موظف الاختبار'},voided_at:null};
  await context.route('**/api/v1/**',async route=>{
   const r=route.request(),url=new URL(r.url()),p=url.pathname,method=r.method(),body=r.postDataJSON();calls.push({p,method,search:url.search});
   const headers={'access-control-allow-origin':'*','access-control-allow-headers':'*','access-control-allow-methods':'*'};
   const json=(data,status=200)=>route.fulfill({headers,status,json:data});const one=data=>json({data});
   if(method==='OPTIONS')return route.fulfill({status:204,headers});
   if(p.endsWith('/auth/handoff/exchange'))return json({token:'isolated-test-token',data:user,redirect:{path:'/reports'}});
   if(p.endsWith('/admin/me'))return one(user);
   if(p.endsWith('/notifications'))return json({...pageOf(notes),meta:{...pageOf(notes).meta,unread_count:notes.filter(n=>!n.read_at).length}});
   if(p.endsWith('/notifications/read-all')){mutations.push({p,body});notes=notes.map(n=>({...n,read_at:'2026-10-27T12:00:00Z'}));return json({meta:{unread_count:0}});}
   if(p.includes('/notifications/')&&p.endsWith('/read')){mutations.push({p,body});if(failRead)return json({message:'فشل قراءة الاختبار'},500);notes=notes.map(n=>({...n,read_at:'2026-10-27T12:00:00Z'}));return json({data:{id:'uuid-order',read_at:notes[0].read_at},meta:{unread_count:0}});}
   if(p.endsWith('/reports/sales'))return one({rows:[{period:'2026-10-01',orders_count:2,gross_sales:1234,discounts:34,tax:0,shipping:20,net_sales:1220}],totals:{orders_count:2,gross_sales:1234,discounts:34,tax:0,shipping:20,net_sales:1220}});
   if(p.endsWith('/reports/inventory'))return one({valuation:[{branch_id:7,branch_name:'فرع الاختبار',variants_count:3,total_qty:8,total_value:4567}],total_value:4567,dead_stock_days:90,dead_stock:[]});
   if(p.endsWith('/reports/profit'))return one({net_sales_revenue:1000,missing_cost_movements:2,cogs:600,gross_profit:400,total_expenses:100,net_profit:300});
   if(p.endsWith('/reports/shifts'))return one({rows:[{...shift,shift_id:21,cashier_name:'الكاشير الفعلي'}],totals:{shifts_count:1,cash_sales:800,card_sales:500,shifts_with_difference:1}});
   if(p.endsWith('/branches'))return json(pageOf([{id:7,name:'فرع الاختبار'}]));
   if(p.endsWith('/orders'))return json(pageOf([order],Number(url.searchParams.get('page')||1),41));
   if(p.endsWith('/orders/stats'))return one({pending:2,processing:3,needs_attention:1,requires_refund:0});
   if(p.endsWith('/orders/71')){if(failOrder)return json({message:'طلب غير موجود'},404);return one(order);}
   if(p.endsWith('/returns'))return json(pageOf([ret]));
   if(p.endsWith('/returns/81'))return one(ret);
   if(p.endsWith('/returns/81/receive')){mutations.push({p,body});ret={...ret,status:'received',items:ret.items.map(i=>({...i,received_good_qty:1,received_damaged_qty:0}))};return one(ret);}
   if(p.endsWith('/returns/81/refund')){mutations.push({p,body});ret={...ret,status:'refunded',refund_method:body.refund_method};return one(ret);}
   if(p.endsWith('/treasuries'))return json(pageOf([treasury]));
   if(p.endsWith('/expenses/51/void')){mutations.push({p,body});expense={...expense,voided_at:'2026-10-28T12:00:00Z',void_reason:body.void_reason};return one(expense);}
   if(p.endsWith('/expenses'))return json(pageOf([expense]));
   if(p.endsWith('/treasury-transfers'))return json(pageOf([]));
   if(p.endsWith('/shifts'))return json(pageOf([shift]));
   if(p.endsWith('/shifts/21'))return one(shift);
   if(p.endsWith('/quotations'))return json(pageOf([quote]));
   if(p.endsWith('/quotations/31'))return one(quote);
   if(p.endsWith('/payment-notices'))return json(pageOf([]));
   if(p.endsWith('/users'))return json(pageOf([user]));
   if(p.endsWith('/customers'))return json(pageOf([{id:1,name:'عميل الجملة'}]));
   if(p.endsWith('/shipping-companies'))return json(pageOf([{id:1,name:'شركة الشحن',code:'SHIP',is_active:true,tracking_url_template:null,has_api_credentials:false}]));
   if(p.endsWith('/shipping-zones'))return json(pageOf([]));
   if(p.endsWith('/shipping-rates'))return json(pageOf([]));
   if(p.endsWith('/customer-groups'))return json(pageOf([]));
   if(p.endsWith('/price-lists'))return json(pageOf([]));
   if(p.endsWith('/promotions'))return json(pageOf([]));
   if(p.endsWith('/coupons'))return json(pageOf([]));
   if(p.endsWith('/dashboard'))return one({});
   return json(pageOf([]));
  });
  await page.goto(base+'/auth/callback#code=isolated-test-handoff');
  await page.getByText('حركة المبيعات',{exact:true}).waitFor();
  const op=()=>page.locator('[data-operations-mock]');
  assert.ok(await op().getByText('1,234',{exact:false}).count(),'real report values rendered');
  const dates=op().locator('input[type=date]');assert.match(await dates.nth(0).inputValue(), /^\d{4}-\d{2}-01$/, 'report defaults use an ISO month boundary in Cairo');assert.match(await dates.nth(1).inputValue(), /^\d{4}-\d{2}-\d{2}$/, 'today uses an ISO date');await dates.nth(0).fill('2024-01-01');await dates.nth(1).fill('2026-01-01');
  await op().getByText('أقصى مدى للتقرير 366 يوماً',{exact:true}).waitFor();
  const before=calls.filter(c=>c.p.endsWith('/reports/sales')).length;await page.waitForTimeout(500);assert.equal(calls.filter(c=>c.p.endsWith('/reports/sales')).length,before,'invalid report never requested');
  await dates.nth(0).fill('2026-10-01');await dates.nth(1).fill('2026-10-27');await op().getByText('حركة المبيعات',{exact:true}).waitFor();
  await op().getByRole('button',{name:'المخزون',exact:true}).click();await op().getByText('4,567',{exact:false}).first().waitFor();
  await page.waitForTimeout(1500);await page.screenshot({path:path.join(out,'reports.png'),fullPage:true});
  await op().getByRole('button',{name:'الأرباح',exact:true}).click();await op().getByRole('alert').filter({hasText:'حساب الربح غير مكتمل'}).waitFor();
  await page.goto(base+'/#/orders');await op().getByText('ORD-RUNTIME',{exact:true}).waitFor();await op().getByRole('button',{name:'2',exact:true}).click();await page.waitForTimeout(300);assert.ok(calls.some(c=>c.p.endsWith('/orders')&&c.search.includes('page=2')));
  await op().getByText('ORD-RUNTIME',{exact:true}).click();await op().getByText('عنوان الاختبار الحقيقي',{exact:true}).waitFor();assert.equal(await op().getByText('WELCOME50',{exact:true}).count(),0);
  await page.waitForTimeout(1500);await page.screenshot({path:path.join(out,'order.png'),fullPage:true});
  await page.goto(base+'/#/returns/81');await op().getByText('سبب اختبار حقيقي',{exact:true}).waitFor();await op().getByRole('button',{name:'الاستلام والفحص',exact:true}).click();
  const quantities=op().locator('input[type=number]');assert.equal(await quantities.nth(0).inputValue(),'');assert.equal(await quantities.nth(1).inputValue(),'');
  await op().getByRole('button',{name:'تأكيد الاستلام',exact:true}).click();await op().getByText('أدخل الكمية السليمة والتالفة لكل بند بعد الفحص الفعلي',{exact:true}).waitFor();assert.ok(!mutations.some(m=>m.p.endsWith('/receive')));
  await quantities.nth(0).fill('1');await quantities.nth(1).fill('0');await op().getByRole('button',{name:'تأكيد الاستلام',exact:true}).click();await page.waitForTimeout(350);
  const receipt=mutations.find(m=>m.p.endsWith('/receive'));assert.deepEqual(receipt.body.items,[{id:831,received_good_qty:1,received_damaged_qty:0}]);
  await op().getByRole('button',{name:'رد المبلغ',exact:true}).click();await op().locator('select').last().selectOption('bank');await op().getByRole('button',{name:'تأكيد الرد',exact:true}).click();await page.waitForTimeout(350);assert.equal(mutations.find(m=>m.p.endsWith('/refund')).body.refund_method,'bank_transfer');
  await page.goto(base+'/#/notifications');await op().getByText('إشعار طلب حقيقي',{exact:true}).waitFor();failRead=true;await op().getByRole('button',{name:'تعليم كمقروء',exact:true}).click();await op().getByText('فشل قراءة الاختبار',{exact:false}).waitFor();await op().getByRole('button',{name:'تعليم كمقروء',exact:true}).waitFor();failRead=false;await op().getByRole('button',{name:'تعليم المحمّل كمقروء',exact:true}).click();await page.waitForTimeout(250);assert.equal(await op().getByRole('button',{name:'تعليم كمقروء',exact:true}).count(),0);
  await page.goto(base+'/#/finance');await op().getByText('خزينة الاختبار',{exact:true}).first().waitFor();assert.ok((await op().locator('.operations-mock-container').innerText()).includes('9,876.50'));await page.waitForTimeout(1500);await page.screenshot({path:path.join(out,'finance.png'),fullPage:true});
  await page.goto(base+'/#/finance?sub=exp');await op().getByText('EXP-RUNTIME',{exact:true}).waitFor();await op().getByRole('button',{name:'إلغاء المصروف',exact:true}).click();
  const voidModal=op().locator('.modal');await voidModal.locator('textarea').fill('سند مسجل بالخطأ');await voidModal.getByRole('button',{name:'تأكيد الإلغاء',exact:true}).click();await op().getByText('ملغى',{exact:true}).waitFor();
  assert.deepEqual(mutations.find(m=>m.p.endsWith('/expenses/51/void')).body,{void_reason:'سند مسجل بالخطأ'});assert.equal(await op().getByRole('button',{name:'إلغاء المصروف',exact:true}).count(),0);
  await page.goto(base+'/#/shifts');await op().getByText('SH-RUNTIME',{exact:true}).waitFor();await op().getByText('SH-RUNTIME',{exact:true}).click();await op().getByText('الكاشير الفعلي',{exact:true}).first().waitFor();
  await page.goto(base+'/#/wholesale');await op().getByText('QT-RUNTIME',{exact:true}).waitFor();
  for(const route of ['shipping','pricing','promos']){await page.goto(base+'/#/'+route);await op().waitFor();await page.waitForTimeout(300);}
  for(const route of ['suppliers','purchasing','brands']){const n=calls.length;await page.goto(base+'/#/'+route);await op().getByText('هذا القسم متوقف',{exact:true}).waitFor();assert.equal(calls.slice(n).filter(c=>new RegExp('/'+route+'(?:/|$)').test(c.p)).length,0);}
  user.permissions=[];await page.goto(base+'/#/orders/71');await page.reload();await page.getByText('ليس لديك صلاحية عرض هذه الصفحة.',{exact:true}).waitFor();
  user.permissions=['orders.view'];await page.reload();await op().getByText('ORD-RUNTIME',{exact:true}).waitFor();
  for(const name of ['تغيير الحالة','تحديث الدفع','فك التخصيص','إنشاء شحنة'])assert.equal(await op().getByRole('button',{name,exact:true}).isDisabled(),true,'permission gate: '+name);
  failOrder=true;await page.reload();await op().getByRole('button',{name:'إعادة المحاولة',exact:true}).first().waitFor();assert.equal(await op().getByText('حقيبة الاختبار',{exact:true}).count(),0);
  assert.deepEqual(errors,[]);console.log('Operations browser smoke passed: reports, periods, order pagination/detail, returns receive/refund, notification rollback/UUID, finance, shifts, wholesale, commerce, retired routes and 404 retry.');
  await context.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
