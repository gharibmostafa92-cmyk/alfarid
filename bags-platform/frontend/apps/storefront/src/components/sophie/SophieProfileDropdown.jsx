'use client';

import React, { useState, useEffect, useRef } from 'react';
const FALLBACK_USER = {
  name: 'My account',
  initials: 'MA',
  email: '',
  role: 'Member',
  avatar: null,
  walletBalance: null,
  menuItems: [
    { id: 'profile', label: 'My profile', icon: 'fa-regular fa-user', type: 'item' },
    { id: 'signout', label: 'Sign out', icon: 'fa-solid fa-arrow-from-bracket', isSignOut: true, type: 'item' },
  ],
};
import { formatStorePrice } from '@alfarid/utils';
import { StoreImage } from '../StoreImage';

/**
 * SophieProfileDropdown Component
 *
 * Luxury avatar + dropdown card with account links.
 * - No avatar image: circle with the user's initials.
 * - Promo ribbon renders only when `user.ribbonText` is set (storefront never sends one).
 * - Wallet line renders only when `user.walletBalance` is present, formatted via formatStorePrice (EGP).
 * - Keyboard: ArrowUp/ArrowDown move between items, Escape closes and refocuses the trigger.
 *
 * @param {Object} props
 * @param {Object} [props.user] User data override (profile VM from toProfileVM)
 * @param {Function} [props.onSelectAction] Callback when an item is clicked: (actionId) => void
 * @param {Function} [props.onApplyPromo] Callback when ribbon is clicked: (promoCode) => void
 * @param {string} [props.className] Additional CSS class
 */
export function SophieProfileDropdown({
  user = FALLBACK_USER,
  onSelectAction,
  onApplyPromo,
  className = ''
}) {
  const [isOpen, setIsOpen] = useState(false);
  const containerRef = useRef(null);
  const triggerRef = useRef(null);
  const itemRefs = useRef([]);

  const actionableItems = (user.menuItems ?? []).filter((item) => item.type !== 'divider');
  const initials = user.initials
    || String(user.name ?? '?').split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase()
    || '?';
  const walletNumber = user.walletBalance === null || user.walletBalance === undefined || user.walletBalance === ''
    ? null
    : Number(user.walletBalance);
  const showWallet = walletNumber !== null && Number.isFinite(walletNumber);

  // Close when clicking outside; Escape closes
  useEffect(() => {
    function handleClickOutside(event) {
      if (containerRef.current && !containerRef.current.contains(event.target)) {
        setIsOpen(false);
      }
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        setIsOpen(false);
      }
    }

    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
      document.addEventListener('keydown', handleKeyDown);
    }
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen]);

  // Return focus to the trigger after closing via Escape
  useEffect(() => {
    if (!isOpen && document.activeElement && containerRef.current?.contains(document.activeElement)) {
      triggerRef.current?.focus();
    }
  }, [isOpen]);

  const focusItem = (index) => {
    const el = itemRefs.current[index];
    if (el) el.focus();
  };

  const handleMenuKeyDown = (event, index) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      focusItem((index + 1) % actionableItems.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      focusItem((index - 1 + actionableItems.length) % actionableItems.length);
    } else if (event.key === 'Home') {
      event.preventDefault();
      focusItem(0);
    } else if (event.key === 'End') {
      event.preventDefault();
      focusItem(actionableItems.length - 1);
    }
  };

  const handleToggle = (e) => {
    e.stopPropagation();
    setIsOpen((prev) => !prev);
  };

  const handleRibbonClick = (e) => {
    e.stopPropagation();
    setIsOpen(false);
    if (onApplyPromo) {
      if (user.promoCode) onApplyPromo(user.promoCode);
    } else if (onSelectAction) {
      onSelectAction('ribbon');
    }
  };

  const handleItemClick = (item) => {
    setIsOpen(false);
    if (onSelectAction) {
      onSelectAction(item.id);
    }
  };

  let actionIndex = -1;

  return (
    <div
      ref={containerRef}
      className={`profile-menu-wrap ${isOpen ? 'is-open' : ''} ${className}`}
      id="profile-menu-wrap"
    >
      {/* Avatar Button Trigger */}
      <button
        type="button"
        ref={triggerRef}
        className="profile-avatar-btn"
        id="profile-trigger-btn"
        onClick={handleToggle}
        onKeyDown={(event) => {
          if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
          event.preventDefault();
          setIsOpen(true);
          requestAnimationFrame(() => focusItem(event.key === 'ArrowDown' ? 0 : actionableItems.length - 1));
        }}
        aria-label="User Profile Menu"
        aria-haspopup="menu"
        aria-expanded={isOpen}
        title={`${user.name} (${user.role})`}
      >
        <span className="profile-avatar-inner">
          {user.avatar ? (
            <StoreImage src={user.avatar} alt={user.name} />
          ) : (
            <span className="text-white text-xs font-semibold" aria-hidden="true">
              {initials}
            </span>
          )}
        </span>
      </button>

      {/* Floating Profile Card */}
      <div
        className="profile-dropdown-card"
        id="profile-dropdown"
        role="menu"
        inert={!isOpen}
        aria-hidden={!isOpen}
        onBlur={(event) => { if (!containerRef.current?.contains(event.relatedTarget)) setIsOpen(false); }}
        aria-label="Profile Menu"
      >
        {/* Account identity header */}
        <div className="profile-identity">
          <p className="profile-identity-name">{user.name}</p>
          {user.email ? <p className="profile-identity-email">{user.email}</p> : null}
          {showWallet ? <p className="profile-identity-wallet">Wallet: {formatStorePrice(walletNumber)}</p> : null}
        </div>

        {/* Promotional ribbon: only when explicitly provided (never for storefront accounts) */}
        {user.ribbonText && (
          <div className="profile-ribbon-wrap">
            <button
              type="button"
              className="profile-ribbon"
              id="profile-ribbon-btn"
              onClick={handleRibbonClick}
              title={`Click to apply ${user.ribbonText} promo code!`}
            >
              {user.ribbonText}
            </button>
          </div>
        )}

        {/* Menu Items List */}
        <div className="profile-menu-list">
          {user.menuItems?.map((item) => {
            if (item.type === 'divider') {
              return <div key={item.id} className="profile-menu-divider" role="separator" />;
            }

            actionIndex += 1;
            const currentIndex = actionIndex;
            const itemClasses = [
              'profile-menu-item',
              item.highlighted ? 'highlighted' : '',
              item.isSignOut ? 'sign-out' : ''
            ]
              .filter(Boolean)
              .join(' ');

            return (
              <button
                key={item.id}
                ref={(el) => { itemRefs.current[currentIndex] = el; }}
                type="button"
                className={itemClasses}
                onClick={() => handleItemClick(item)}
                onKeyDown={(e) => handleMenuKeyDown(e, currentIndex)}
                role="menuitem"
              >
                <i className={item.icon} aria-hidden="true" />
                <span>{item.label}</span>
              </button>
            );
          })}
        </div>
      </div>
    </div>
  );
}

export default SophieProfileDropdown;
