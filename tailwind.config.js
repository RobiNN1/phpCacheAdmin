/** @type {import('tailwindcss').Config} */
module.exports = {
    content: ['./templates/**/*.twig'],
    darkMode: 'class',
    theme: {
        extend: {},
    },
    safelist: [
        {
            pattern: /^bg-(slate|red|emerald|sky)-(50|100|500)?$/,
        },
        {
            pattern: /^bg-(slate|red|emerald|sky)-600?$/,
            variants: ['hover']
        },
        {
            pattern: /^text-(slate|red|emerald|sky)-(500|900)?$/,
        },
        {
            pattern: /^text-(slate|red|emerald|sky)-700?$/,
            variants: ['hover']
        },
        {
            pattern: /^border-(slate|red|emerald|sky)-300?$/,
            variants: ['focus']
        },
        {
            pattern: /^ring-(slate|red|emerald|sky)-(100|200|300)?$/,
            variants: ['focus']
        },
        'ml-64',
        'p-4',
        'bg-green-600',
        'bg-red-600',
    ],
    corePlugins: {
        textOpacity: false,
        backgroundOpacity: false,
        borderOpacity: false,
        divideOpacity: false,
        placeholderOpacity: false,
        ringOpacity: false,
    },
    plugins: [],
}
