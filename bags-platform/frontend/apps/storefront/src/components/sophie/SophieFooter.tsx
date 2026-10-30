'use client';

import React from 'react';
import Link from 'next/link';
import { STORE_ROUTES } from '@/lib/store-routes';
import { BrandLogo, useBranding } from '@alfarid/ui/branding';

export function SophieFooter() {
  const { branding } = useBranding();
  return (
    <div className="site-footer-wrap relative mt-5 bg-white" id="footer-contact">
      {/* Top Silhouette Border: Fashion District & Iconic Handbags/Backpacks Skyline */}
      <div className="footer-skyline" aria-hidden="true">
        <svg viewBox="0 0 1440 90" preserveAspectRatio="none" fill="#151823" xmlns="http://www.w3.org/2000/svg">
          {/* Base horizon bar */}
          <rect x="0" y="76" width="1440" height="16" />
          {/* Left boutique & structured tote silhouette */}
          <polygon points="0,78 0,54 22,54 22,42 48,42 48,60 64,60 64,28 102,28 102,52 118,52 118,78" />
          {/* Structured Handbag Silhouette 1 */}
          <path d="M132,78 L138,40 L186,40 L192,78 Z" />
          <path d="M150,40 C150,22 174,22 174,40" fill="none" stroke="#151823" strokeWidth="5" />
          {/* Boutique blocks */}
          <polygon points="205,78 205,46 238,46 238,22 278,22 278,56 296,56 296,34 336,34 336,62 354,62 354,78" />
          {/* Dome Backpack Silhouette */}
          <path d="M368,78 L368,42 C368,24 414,24 414,42 L414,78 Z" />
          <path d="M382,28 C382,16 400,16 400,28" fill="none" stroke="#151823" strokeWidth="4.5" />
          {/* Mid-left fashion towers */}
          <polygon points="428,78 428,50 456,50 456,30 492,30 492,58 512,58 512,38 558,38 558,48 584,48 584,64 606,64 606,78" />
          {/* Center Luxury Tote & Shopper Bag Silhouette */}
          <polygon points="618,78 624,32 678,32 684,78" />
          <path d="M638,32 C638,14 664,14 664,32" fill="none" stroke="#151823" strokeWidth="5" />
          {/* Center-right boutique skyline */}
          <polygon points="698,78 698,44 734,44 734,26 774,26 774,52 798,52 798,36 838,36 838,60 858,60 858,42 894,42 894,66 914,66 914,78" />
          {/* Messenger / Satchel Bag Silhouette */}
          <rect x="926" y="42" width="58" height="36" rx="4" />
          <path d="M942,42 C942,26 968,26 968,42" fill="none" stroke="#151823" strokeWidth="4.5" />
          {/* Right high-street skyline */}
          <polygon points="998,78 998,54 1026,54 1026,20 1068,20 1068,46 1094,46 1094,28 1136,28 1136,52 1158,52 1158,36 1196,36 1196,60 1214,60 1214,78" />
          {/* Right Luxury Backpack & Handbag Pair */}
          <path d="M1226,78 L1226,40 C1226,22 1270,22 1270,40 L1270,78 Z" />
          <path d="M1240,26 C1240,15 1256,15 1256,26" fill="none" stroke="#151823" strokeWidth="4.5" />
          <polygon points="1284,78 1284,48 1318,48 1318,30 1356,30 1356,56 1384,56 1384,38 1418,38 1418,58 1440,58 1440,78" />
        </svg>
      </div>

      {/* Main Luxury Dark Footer */}
      <footer className="bg-[#151823] text-[#a4abba] pt-16 pb-14 font-['Poppins'] relative">
        <div className="max-w-[1140px] mx-auto px-5">
          {/* 4-Column Main Grid */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 lg:gap-10 mb-10 lg:mb-14">
            {/* Column 1: ABOUT US */}
            <div>
              <h4 className="font-['Oswald'] text-[16px] font-normal uppercase tracking-[1.1px] text-[#e4e8f0] mb-5">
                ABOUT US
              </h4>
              <p className="text-[12.5px] leading-[1.78] text-[#a4abba] mb-3.5">
                {branding.name} crafts contemporary ladies backpacks, structured leather totes, and anti-theft travel companions designed for effortless everyday elegance.
              </p>
              <p className="text-[12.5px] leading-[1.78] text-[#a4abba]">
                Every silhouette blends water-resistant materials, ergonomic comfort, and refined hardware to elevate your daily commute and weekend escapes.
              </p>
            </div>

            {/* Column 2: ADDRESS */}
            <div>
              <h4 className="font-['Oswald'] text-[16px] font-normal uppercase tracking-[1.1px] text-[#e4e8f0] mb-5">
                ADDRESS
              </h4>
              <ul className="space-y-4 text-[12.5px] leading-[1.65]">
                {branding.email && <li className="flex items-start gap-3.5">
                  <span className="text-[#8c93a4] w-4 text-center mt-1 shrink-0">✉</span>
                  <a href={`mailto:${branding.email}`} className="hover:text-[#1bc6cf] transition-colors break-all">
                    {branding.email}
                  </a>
                </li>}
                {branding.phone && <li className="flex items-start gap-3.5">
                  <span className="text-[#8c93a4] w-4 text-center mt-1 shrink-0">☎</span>
                  <a href={`tel:${branding.phone}`} dir="ltr">{branding.phone}</a>
                </li>}
                {branding.address && <li className="flex items-start gap-3.5">
                  <span className="text-[#8c93a4] w-4 text-center mt-1 shrink-0">📍</span>
                  <div dir="auto" className="whitespace-pre-line">{branding.address}</div>
                </li>}
              </ul>
            </div>

            {/* Column 3: COMPANY */}
            <div>
              <h4 className="font-['Oswald'] text-[16px] font-normal uppercase tracking-[1.1px] text-[#e4e8f0] mb-5">
                COMPANY
              </h4>
              <ul className="space-y-2.5 text-[12.5px]">
                <li>
                  <Link href="/" className="inline-flex items-center gap-2 text-[#a4abba] hover:text-[#e4e8f0] hover:translate-x-1 transition-all">
                    <span className="text-[#6c7383]">&rsaquo;</span> Home
                  </Link>
                </li>
                <li>
                  <a href={STORE_ROUTES.shop({ sort: 'best_selling' })} className="inline-flex items-center gap-2 text-[#a4abba] hover:text-[#e4e8f0] hover:translate-x-1 transition-all">
                    <span className="text-[#6c7383]">&rsaquo;</span> Best Sellers
                  </a>
                </li>
                <li>
                  <a href={STORE_ROUTES.shop()} className="inline-flex items-center gap-2 text-[#a4abba] hover:text-[#e4e8f0] hover:translate-x-1 transition-all">
                    <span className="text-[#6c7383]">&rsaquo;</span> Collections &amp; Accessories
                  </a>
                </li>
                <li>
                  <a href={STORE_ROUTES.shop({ sort: 'newest' })} className="inline-flex items-center gap-2 text-[#a4abba] hover:text-[#e4e8f0] hover:translate-x-1 transition-all">
                    <span className="text-[#6c7383]">&rsaquo;</span> New Arrivals
                  </a>
                </li>
                <li>
                  <a href="/wholesale" className="inline-flex items-center gap-2 text-[#a4abba] hover:text-[#e4e8f0] hover:translate-x-1 transition-all">
                    <span className="text-[#6c7383]">&rsaquo;</span> Wholesale Portal
                  </a>
                </li>
                <li>
                  <a href="#footer-contact" className="inline-flex items-center gap-2 text-[#a4abba] hover:text-[#e4e8f0] hover:translate-x-1 transition-all">
                    <span className="text-[#6c7383]">&rsaquo;</span> Contact Us
                  </a>
                </li>
              </ul>
            </div>

            {/* Column 4: NEWSLETTER */}
            <div id="footer-newsletter">
              <h4 className="font-['Oswald'] text-[16px] font-normal uppercase tracking-[1.1px] text-[#e4e8f0] mb-5">
                NEWSLETTER
              </h4>
              <p className="text-[12.5px] leading-[1.75] text-[#a4abba] mb-5">
                Newsletter subscriptions will be available soon.
              </p>
            </div>
          </div>

          {/* Social Icons Horizontal Divider */}
          <div className="flex items-center justify-center gap-5 max-w-[780px] mx-auto mb-9 before:content-[''] before:flex-1 before:h-[1px] before:bg-[#272c3a] after:content-[''] after:flex-1 after:h-[1px] after:bg-[#272c3a]">
            <div className="flex items-center gap-2.5">
              <a
                href="#footer-contact"
                aria-label="Facebook"
                className="w-11 h-11 rounded-full bg-[#1bc6cf] text-white flex items-center justify-center text-sm hover:bg-[#14aeb6] hover:-translate-y-0.5 hover:shadow-[0_6px_16px_rgba(27,198,207,0.35)] transition-all"
              >
                f
              </a>
              <a
                href="#footer-contact"
                aria-label="Twitter"
                className="w-11 h-11 rounded-full bg-[#1bc6cf] text-white flex items-center justify-center text-sm hover:bg-[#14aeb6] hover:-translate-y-0.5 hover:shadow-[0_6px_16px_rgba(27,198,207,0.35)] transition-all"
              >
                𝕏
              </a>
              <a
                href="#footer-contact"
                aria-label="Instagram"
                className="w-11 h-11 rounded-full bg-[#1bc6cf] text-white flex items-center justify-center text-sm hover:bg-[#14aeb6] hover:-translate-y-0.5 hover:shadow-[0_6px_16px_rgba(27,198,207,0.35)] transition-all"
              >
                📷
              </a>
            </div>
          </div>

          {/* Bottom Bag Boutique Emblem & Copyright */}
          <div className="text-center flex flex-col items-center">
            <svg className="w-[148px] h-[82px] mb-2" viewBox="0 0 180 96" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
              <line x1="16" y1="86" x2="164" y2="86" stroke="#6c7383" strokeWidth="2" strokeLinecap="round" />
              {/* Left Structured Handbag */}
              <path d="M36 46 C36 32, 56 32, 56 46" stroke="#dce0e8" strokeWidth="3.2" strokeLinecap="round" fill="none" />
              <polygon points="26,85 30,46 60,46 64,85" fill="#dce0e8" />
              <polygon points="46,85 46,46 60,46 64,85" fill="#9299a8" />
              <rect x="42" y="55" width="6" height="8" rx="1" fill="#151823" />

              {/* Center Tall Signature Backpack */}
              <path d="M79 22 C79 10, 101 10, 101 22" stroke="#dce0e8" strokeWidth="3.5" strokeLinecap="round" fill="none" />
              <path d="M68 85 L68 34 C68 20, 112 20, 112 34 L112 85 Z" fill="#dce0e8" />
              <path d="M90 20 C104 20, 112 26, 112 34 L112 85 L90 85 Z" fill="#8b92a1" />
              <path d="M73 36 Q90 46 107 36" stroke="#151823" strokeWidth="2.2" fill="none" />
              <rect x="76" y="54" width="28" height="22" rx="4" fill="#151823" fillOpacity="0.22" />
              <rect x="87" y="40" width="6" height="10" rx="1.5" fill="#151823" />
              <circle cx="80" cy="64" r="1.8" fill="#151823" />
              <circle cx="100" cy="64" r="1.8" fill="#151823" />

              {/* Right Chic Boutique Tote & Tree Motif */}
              <path d="M122 54 C122 43, 136 43, 136 54" stroke="#8b92a1" strokeWidth="2.8" strokeLinecap="round" fill="none" />
              <rect x="116" y="54" width="26" height="31" rx="2" fill="#b7becb" />
              <rect x="129" y="54" width="13" height="31" rx="1" fill="#7f8696" />

              <circle cx="151" cy="54" r="12" fill="#8b92a1" />
              <path d="M151 42 A12 12 0 0 1 163 54 A12 12 0 0 1 151 66 Z" fill="#6d7483" />
              <line x1="151" y1="62" x2="151" y2="85" stroke="#dce0e8" strokeWidth="2.6" strokeLinecap="round" />
              <line x1="151" y1="69" x2="156" y2="63" stroke="#dce0e8" strokeWidth="2" strokeLinecap="round" />
            </svg>

            <div className="font-['Oswald'] text-[21px] font-normal tracking-[3.5px] uppercase text-[#8c93a4] mb-3.5">
              <BrandLogo className="w-12 h-12 mx-auto mb-2" />
              <span dir="auto" className="break-words">{branding.name}</span>
            </div>
            <p className="text-[11px] leading-[1.65] text-[#5c6272] max-w-[420px]">
              Copyright &copy; {new Date().getFullYear()} {branding.name}. All rights reserved.<br />
              <a href="#top" className="text-[#a4abba] hover:text-[#1bc6cf] transition-colors">
                {branding.name}
              </a>
            </p>
          </div>
        </div>
      </footer>
    </div>
  );
}
