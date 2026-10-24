const path = require('node:path');
const base = path.join(__dirname,'../apps/backoffice/src/pages/admin/admin-mock/api/__fixtures__');
const fixture = name => structuredClone(require(path.join(base,name+'.json')));
module.exports = async function adminRoutes(context) {
  const state = {me:fixture('me'),users:fixture('users'),roles:fixture('roles'),settings:fixture('settings'),requests:[],rejectEmail:false,rejectDelete:false,restricted:false};
  state.users.data.forEach(user => user.version ??= 0);
  state.roles.data.forEach(role => role.version ??= 0);
  await context.route('**/api/v1/admin/**', async route => {
    const request=route.request(), url=new URL(request.url()), resource=url.pathname.split('/admin/')[1], method=request.method();
    const body=['POST','PUT','PATCH','DELETE'].includes(method) && request.postData() ? request.postDataJSON() : undefined;
    state.requests.push({resource,method,body,params:url.searchParams});
    const fail=(status,message,errors) => route.fulfill({status,json:{message,errors}});
    if(resource === 'me') return route.fulfill({json:state.me});
    if(resource === 'permissions' || resource === 'branches') return state.restricted ? fail(403,'لا تملك صلاحية عرض هذه القائمة') : route.fulfill({json:fixture(resource)});
    if(resource === 'settings') {
      if(method === 'PUT') {
        const patch = {...body}; delete patch.expected;
        state.lastSettingsPatch=patch;
        if (!body.expected) return fail(422,'القيم الأصلية مطلوبة');
        const current = Object.assign({}, ...Object.values(state.settings.data));
        for (const [key,value] of Object.entries(body.expected)) if (JSON.stringify(current[key] ?? '') !== JSON.stringify(value ?? '')) return fail(409,'تم تغيير إعداد أو عداد منذ فتح الصفحة؛ أعد تحميل الإعدادات قبل الحفظ.');
        const invalid=Object.keys(body).find(k => k.endsWith('_padding') && body[k]>20);
        if(invalid) return fail(422,'البيانات غير صالحة',{[invalid]:['يجب ألا يتجاوز حشو الأصفار 20']});
        for(const [key,value] of Object.entries(patch)) for(const group of Object.values(state.settings.data)) if(key in group) group[key]=key.endsWith('_next_number') ? Math.max(group[key],value) : value;
      }
      return route.fulfill({json:state.settings});
    }
    if(resource.startsWith('roles')) {
      if(method === 'DELETE') {
        if(state.rejectDelete) return fail(409,'الدور مستخدم ولا يمكن حذفه');
        state.roles.data=state.roles.data.filter(r => r.id !== Number(resource.split('/')[1]));
        return route.fulfill({status:204});
      }
      if(method === 'POST' || method === 'PATCH') {
        const current = state.roles.data.find(r => r.id === Number(resource.split('/')[1]));
        if (method === 'PATCH' && body.expected_version !== current.version) return fail(409,'تم تعديل السجل بواسطة مستخدم آخر؛ أعد التحميل قبل الحفظ.');
        const role=method === 'POST' ? {...state.roles.data[1],id:99,users_count:0,...body} : Object.assign(state.roles.data.find(r => r.id === Number(resource.split('/')[1])),body);
        if(method === 'POST') { role.version = 0; state.roles.data.push(role); }
        else role.version++;
        return route.fulfill({status:method === 'POST'?201:200,json:{data:role}});
      }
      return route.fulfill({json:state.roles});
    }
    if(resource.startsWith('users')) {
      if(method === 'POST' && state.rejectEmail) return fail(422,'البيانات غير صالحة',{email:['هذا البريد مستخدم بالفعل في النظام']});
      if(method !== 'GET') {
        const current = state.users.data.find(u => u.id === Number(resource.split('/')[1]));
        if (method === 'PATCH' && body.expected_version != null && body.expected_version !== current.version) return fail(409,'تم تعديل السجل بواسطة مستخدم آخر؛ أعد التحميل قبل الحفظ.');
        if (method === 'DELETE' && body?.expected_version == null) return fail(422,'رقم النسخة مطلوب');
        const user=method === 'POST' ? {...state.users.data[1],id:99,...body} : Object.assign(state.users.data.find(u => u.id === Number(resource.split('/')[1])),method === 'DELETE'?{is_active:false}:body);
        if(body?.roles) user.roles=body.roles.map(name=>({id:state.roles.data.find(r=>r.name===name).id,name}));
        if(method === 'POST') { user.version = 0; state.users.data.push(user); }
        else user.version++;
        return route.fulfill({json:{data:user}});
      }
      const response=structuredClone(state.users), p=url.searchParams;
      response.data=response.data.filter(u=>(!p.has('is_active') || u.is_active === (p.get('is_active')==='1'))
        && (!p.get('search') || (u.name+' '+u.email).toLowerCase().includes(p.get('search').toLowerCase()))
        && (!p.get('role') || u.roles.some(r=>r.name===p.get('role')))
        && (!p.has('branch_id') || (p.get('branch_id')==='' ? u.branch_id===null : u.branch_id===Number(p.get('branch_id')))));
      response.meta.total=response.data.length; response.meta.from=response.data.length?1:null; response.meta.to=response.data.length||null;
      response.data=response.data.slice(0,Number(p.get('per_page')||8));
      return route.fulfill({json:response});
    }
    return route.fulfill({json:{data:[]}});
  });
  return state;
};
