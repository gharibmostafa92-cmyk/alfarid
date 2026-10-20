/** @type {import('tailwindcss').Config} */
const tailwindPreset = {
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#f0f7ff',
          100: '#e0effe',
          200: '#bae0fd',
          300: '#7cc7fb',
          400: '#38a8f8',
          500: '#0e8ce9',
          600: '#026fc7',
          700: '#0358a1',
          800: '#074b85',
          900: '#0c3f6e',
          950: '#082849',
        },
      },
      fontFamily: {
        sans: ['Cairo', 'Tajawal', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
};

module.exports = tailwindPreset;
