/** @type {import('tailwindcss').Config} */
module.exports = {
    // Classes must appear literally in these files (no string concatenation like `${prefix}:text-sm`).
    content: [
        './templates/base.html.twig',
        './templates/ticketing/**/*.twig',
        './templates/customer/**/*.twig',
        './templates/components/**/*.twig',
        './templates/scanner/**/*.twig',
        './templates/error/**/*.twig',
        './assets/controllers/frontend/**/*.js',
        './assets/controllers/scanner/**/*.js',
        './assets/frontend/**/*.js',
    ],
    // Classes that live in database content (event/stock "disabledReason" HTML, site pages), invisible to the scanner above.
    safelist: ['text-red-600', 'hover:text-red-700', 'underline'],
    plugins: [
        function ({ addBase }) {
            addBase({ 'a, button': { cursor: 'pointer' } });
        },
    ],
};
