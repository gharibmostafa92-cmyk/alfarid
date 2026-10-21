import { FlatCompat } from '@eslint/eslintrc';
import { fileURLToPath } from 'node:url';

const compat = new FlatCompat({ baseDirectory: fileURLToPath(new URL('.', import.meta.url)) });

export default [
  { ignores: ['.next/**', 'next-env.d.ts'] },
  ...compat.extends('next/core-web-vitals'),
  {
    files: ['src/app/layout.tsx'],
    // The shared root layout loads these fonts for every App Router page.
    rules: { '@next/next/no-page-custom-font': 'off' },
  },
];
