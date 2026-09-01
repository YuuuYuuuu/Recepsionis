/** @type {import('tailwindcss').Config} */
export default {
  prefix: 'tw-',
  corePlugins: {
    preflight: false,
  },
  content: ['./index.html', './admin.html', './src/**/*.{js,jsx}', './*.jsx'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Plus Jakarta Sans"', 'Inter', 'sans-serif'],
        display: ['Sora', '"Plus Jakarta Sans"', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
