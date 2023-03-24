/** @type {import('tailwindcss').Config} */
module.exports = {
    content: ['./templates/**/*.twig', './assets/js/scripts.js'],
    darkMode: 'class',
    theme: {
        extend: {
            colors: {
                "primary": {
                    100: "var(--primary-color-100)",
                    200: "var(--primary-color-200)",
                    300: "var(--primary-color-300)",
                    500: "var(--primary-color-500)",
                    600: "var(--primary-color-600)",
                    700: "var(--primary-color-700)",
                    900: "var(--primary-color-900)",
                },
            },
        },
    },
    safelist: [],
    corePlugins: {
        textOpacity: false,
        backgroundOpacity: false,
        borderOpacity: false,
        divideOpacity: false,
        placeholderOpacity: false,
        ringOpacity: false,
    },
    plugins: [],
    experimental: {
        optimizeUniversalDefaults: true
    }
}
