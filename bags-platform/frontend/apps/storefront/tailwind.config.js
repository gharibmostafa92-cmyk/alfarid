/** @type {import('tailwindcss').Config} */
module.exports = {
  presets: [require('@alfarid/config/tailwind.preset.cjs')],
  content: [
    './src/**/*.{js,ts,jsx,tsx,mdx}',
    '../../packages/ui/src/**/*.{js,ts,jsx,tsx}',
  ],
  theme: {
    extend: {},
  },
  plugins: [],
};
