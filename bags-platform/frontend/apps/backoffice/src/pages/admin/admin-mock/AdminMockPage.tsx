import React, { useEffect, useLayoutEffect, useRef, useState, useMemo } from 'react';
import { createPortal } from 'react-dom';
import { useRoles, useApi, useQueryError } from './hooks';
import { adminApi } from './api/endpoints';
import { UsersScreen } from './users';
import { RolesScreen } from './roles';
import { SettingsScreen } from './settings';
import { ToastHost, Icon, EmptyState } from './ui';
import styles from './admin-reference.css?inline';
import { useBranding } from '@alfarid/ui/branding';

export interface AdminMockPageProps {
  page: 'users' | 'roles' | 'settings';
  can?: (perm: string) => boolean;
  darkMode?: boolean;
  theme?: string;
  initialTab?: string;
}

export default function AdminMockPage({ page, can, darkMode, theme, initialTab }: AdminMockPageProps) {
  const { branding } = useBranding();
  const host = useRef<HTMLDivElement>(null);
  const [root, setRoot] = useState<ShadowRoot | null>(null);

  const canFn = can ?? (() => false);
  const visible = (key: string) => canFn(key+'.view') || canFn(key+'.manage');
  const rolesQ = useRoles(visible('roles'));
  const roles = rolesQ.data ?? [];
  const [usersTick, setUsersTick] = useState(0);
  const usersTotal = useApi(signal => visible('users') ? adminApi.countUsers({}, signal) : Promise.resolve(undefined), [usersTick, visible('users')]);
  useQueryError(rolesQ.error); useQueryError(usersTotal.error);
  useEffect(() => { ['alfarid_mock_users','alfarid_mock_roles','alfarid_mock_settings'].forEach(key => localStorage.removeItem(key)); }, []);
  // Parse URL query parameters if present (e.g. ?role=3 or ?roleId=2 or ?tab=store)
  const queryParams = useMemo(() => {
    const raw = window.location.hash.includes('?')
      ? window.location.hash.split('?')[1]
      : window.location.search.replace(/^\?/, '');
    const sp = new URLSearchParams(raw);
    const roleParam = sp.get('role') || sp.get('roleId');
    const roleIdParam = sp.get('roleId') || sp.get('role');
    const tabParam = sp.get('tab');
    return {
      role: roleParam ? (isNaN(Number(roleParam)) ? 'all' : Number(roleParam)) : 'all',
      roleId: roleIdParam && !isNaN(Number(roleIdParam)) ? Number(roleIdParam) : undefined,
      userId: sp.get('userId') && /^[1-9][0-9]*$/.test(sp.get('userId')!) ? Number(sp.get('userId')) : undefined,
      tab: tabParam || initialTab || undefined,
    };
  }, [page, window.location.hash, initialTab]);

  const [detectedDark, setDetectedDark] = useState(() => {
    if (typeof darkMode === 'boolean') return darkMode;
    if (typeof theme === 'string') return theme === 'dark';
    try {
      return localStorage.getItem('vortix_theme') === 'dark';
    } catch {
      return false;
    }
  });

  const isDark = typeof darkMode === 'boolean' ? darkMode : (theme ? theme === 'dark' : detectedDark);

  useLayoutEffect(() => {
    if (host.current) {
      setRoot(host.current.shadowRoot || host.current.attachShadow({ mode: 'open' }));
    }
    const fonts = document.createElement('link');
    fonts.rel = 'stylesheet';
    fonts.href = 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Readex+Pro:wght@400;500;600;700&display=swap';
    document.head.append(fonts);
    return () => fonts.remove();
  }, []);

  useEffect(() => {
    if (host.current) {
      host.current.setAttribute('data-theme', isDark ? 'dark' : 'light');
    }
  }, [isDark]);

  useEffect(() => {
    const handleCustom = (e: any) => {
      if (typeof e.detail?.darkMode === 'boolean') {
        setDetectedDark(e.detail.darkMode);
      }
    };
    window.addEventListener('vortix:theme-change', handleCustom);

    const checkParent = () => {
      const rootNode = host.current?.getRootNode() as ShadowRoot | undefined;
      const parentViewport = host.current?.closest('#app-viewport');
      const target = parentViewport || (rootNode?.host?.querySelector?.('#app-viewport') || rootNode?.host) || document.querySelector('#app-viewport') || document.querySelector('[data-vortix-host]');
      if (!target) return null;
      const dark = target.classList?.contains('theme-dark') || target.getAttribute?.('data-theme') === 'dark';
      setDetectedDark(dark);
      return target;
    };

    const target = checkParent();
    let observer: MutationObserver | undefined;
    if (target) {
      observer = new MutationObserver(() => checkParent());
      observer.observe(target, { attributes: true, attributeFilter: ['class', 'data-theme'] });
    }

    return () => {
      window.removeEventListener('vortix:theme-change', handleCustom);
      observer?.disconnect();
    };
  }, []);

  const handleNavigate = (targetPage: 'users' | 'roles' | 'settings', params?: Record<string, any>) => {
    let hash = `/${targetPage}`;
    if (params) {
      const sp = new URLSearchParams();
      Object.entries(params).forEach(([k, v]) => {
        if (v != null) sp.set(k, String(v));
      });
      const q = sp.toString();
      if (q) hash += `?${q}`;
    }
    window.location.hash = hash;
  };

  const navItems = [
    { id: 'users', label: 'المستخدمون', icon: 'users', count: usersTotal.loading ? undefined : usersTotal.data, path: '/users' },
    { id: 'roles', label: 'الأدوار والصلاحيات', icon: 'shield', count: rolesQ.loading ? undefined : roles.length, path: '/roles' },
    { id: 'settings', label: 'الإعدادات العامة', icon: 'sliders', path: '/settings' },
  ].filter(item => visible(item.id));

  return (
    <div
      ref={host}
      data-catalog-mock={page}
      data-admin-mock={page}
      data-theme={isDark ? 'dark' : 'light'}
      dir="rtl"
    >
      {root &&
        createPortal(
          <>
            <style>{styles}</style>
            <div className={`admin-mock-surface ${isDark ? 'theme-dark' : 'theme-light'} p-4 sm:p-6 lg:p-8 space-y-6`} dir="rtl" lang="ar">
              {/* Top Linking Bar between the 3 Administrative Screens */}
              <div className="flex flex-wrap items-center justify-between gap-3 pb-3 border-b" style={{ borderColor: 'var(--divider-color)' }}>
                <div className="inline-flex p-1 rounded-full v-subcard" role="tablist">
                  {navItems.map((item) => {
                    const active = page === item.id;
                    return (
                      <button
                        type="button"
                        key={item.id}
                        role="tab"
                        aria-selected={active}
                        onClick={() => handleNavigate(item.id as any)}
                        className={`px-4 py-2 rounded-full text-[13px] font-semibold flex items-center gap-2 transition-all duration-150 ${
                          active ? 'shadow-xs' : 'hover:opacity-80'
                        }`}
                        style={{
                          background: active ? 'var(--pill-active-bg)' : 'transparent',
                          color: active ? 'var(--pill-active-text)' : 'var(--text-secondary)',
                        }}
                      >
                        <Icon name={item.icon} className="w-4 h-4" />
                        <span>{item.label}</span>
                        {item.count != null && (
                          <span
                            className="px-2 py-0.5 rounded-full text-[11px] font-medium"
                            style={{
                              background: active ? 'rgba(255, 255, 255, 0.2)' : 'var(--bg-card)',
                              color: active ? 'inherit' : 'var(--text-muted)',
                            }}
                          >
                            {item.count}
                          </span>
                        )}
                      </button>
                    );
                  })}
                </div>

                <div className="flex items-center gap-2 text-[12px]" style={{ color: 'var(--text-muted)' }}>
                  <span>لوحة الإدارة والتحكم</span>
                  <span>•</span>
                  <span dir="auto">{branding.name}</span>
                </div>
              </div>

              {/* Screen Content */}
              {!visible(page) && <EmptyState icon="lock" title="لا تملك صلاحية الوصول لهذه الصفحة" />}
              {visible(page) && page === 'users' && (
                <UsersScreen
                  rolesLoading={rolesQ.loading}
                  can={canFn}
                  onUsersChanged={() => { setUsersTick(t => t+1); rolesQ.reload(); }}
                  roles={roles}
                  onNavigate={handleNavigate}
                  initialRoleFilter={queryParams.role as any}
                  initialUserId={queryParams.userId}
                />
              )}

              {visible(page) && page === 'roles' && rolesQ.error && <EmptyState icon="lock" title={rolesQ.error.message} action={<button type="button" onClick={rolesQ.reload}>إعادة المحاولة</button>} />}
              {visible(page) && page === 'roles' && !rolesQ.error && (
                <RolesScreen
                  roles={roles}
                  rolesLoading={rolesQ.loading}
                  reloadRoles={rolesQ.reload}
                  can={canFn}
                  onNavigate={handleNavigate}
                  initialRoleId={queryParams.roleId}
                />
              )}

              {visible(page) && page === 'settings' && (
                <SettingsScreen can={canFn} onNavigate={handleNavigate} initialTab={queryParams.tab} />
              )}

              <ToastHost />
            </div>
          </>,
          root
        )}
    </div>
  );
}
