/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.php',
        './resources/js/**/*.js',
        './app/**/*.php',
    ],
    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#eef4ff', 100: '#d9e6ff', 200: '#bcd3ff', 300: '#8eb5ff',
                    400: '#598dff', 500: '#3366ff', 600: '#1f47f5', 700: '#1a37e1',
                    800: '#1c31b6', 900: '#1d308f', 950: '#161f57',
                },
            },
            fontFamily: {
                sans: ['system-ui', '-apple-system', '"Segoe UI"', 'Roboto', 'Helvetica', 'Arial', 'sans-serif'],
            },
        },
    },
    // Status-pill / alert colour classes are chosen at runtime from data, so
    // keep them from being purged.
    safelist: [
        { pattern: /^(bg|text|border|ring)-(slate|gray|red|amber|yellow|green|emerald|blue|indigo|violet|rose)-(50|100|200|300|400|500|600|700|800)$/ },
    ],
    plugins: [],
};
