import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
        './resources/js/**/*.tsx',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Source Sans 3', 'Segoe UI', ...defaultTheme.fontFamily.sans],
                display: ['Source Serif 4', ...defaultTheme.fontFamily.serif],
            },
        },
    },

    plugins: [forms({ strategy: 'class' })],
};
