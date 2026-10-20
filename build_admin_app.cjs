const fs = require('fs');
const path = require('path');

const srcPath = path.resolve(__dirname, 'dasign.txt');
const content = fs.readFileSync(srcPath, 'utf8');
const lines = content.split(/\r?\n/);

console.log('Total lines:', lines.length);

// Extract CSS: lines 0 to 503
const cssLines = lines.slice(0, 504);
const outCssPath = path.resolve(__dirname, 'bags-platform/frontend/apps/backoffice/src/index.css');
fs.writeFileSync(outCssPath, cssLines.join('\n'), 'utf8');
console.log('Wrote CSS to', outCssPath, '(', cssLines.length, 'lines )');

// Extract JS: lines 504 to end
let jsContent = lines.slice(504).join('\n');

// Remove duplicate destructured hooks declaration
jsContent = jsContent.replace(
  'const { useState, useEffect, useRef, useMemo, useCallback, createContext, useContext } = React;',
  '// const { useState, useEffect, useRef, useMemo, useCallback, createContext, useContext } = React;'
);

// Update TWEAK_DEFAULTS
jsContent = jsContent.replace(
  'const TWEAK_DEFAULTS = window.ALFARID_TWEAKS;',
  `const TWEAK_DEFAULTS = (typeof window !== 'undefined' && window.ALFARID_TWEAKS) || {
  canViewCost: true,
  canPublish: true,
  canManageRoles: true,
  navMode: 'normal',
  dataState: 'normal',
  publishSim: 'success',
  accent: '#4f7d88',
  band: '#dfe5e9',
};`
);

// Update IMG paths with high quality fallback URLs
const oldImgRegex = /const IMG = \{[\s\S]*?\};/;
const newImgStr = `const IMG = {
  bag1: 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?w=600&auto=format&fit=crop&q=80',
  bag2: 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?w=600&auto=format&fit=crop&q=80',
  bag3: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=600&auto=format&fit=crop&q=80',
  wallet: 'https://images.unsplash.com/photo-1627123424574-724758594e93?w=600&auto=format&fit=crop&q=80',
  bp1: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=600&auto=format&fit=crop&q=80',
  bp2: 'https://images.unsplash.com/photo-1622560480605-d83c853bc5c3?w=600&auto=format&fit=crop&q=80',
  walletPhoto: 'https://images.unsplash.com/photo-1627123424574-724758594e93?w=600&auto=format&fit=crop&q=80',
  shoulder: 'https://images.unsplash.com/photo-1590874103328-eac38a683ce7?w=600&auto=format&fit=crop&q=80',
};`;
jsContent = jsContent.replace(oldImgRegex, newImgStr);

const header = `// @ts-nocheck
import React, { useState, useEffect, useRef, useMemo, useCallback, createContext, useContext } from 'react';
`;

const footer = `
export default App;
export { App as AdminApp };
`;

const finalJs = header + '\n' + jsContent + '\n' + footer;
const outJsPath = path.resolve(__dirname, 'bags-platform/frontend/apps/backoffice/src/AdminApp.jsx');
fs.writeFileSync(outJsPath, finalJs, 'utf8');
console.log('Wrote AdminApp.jsx to', outJsPath);
