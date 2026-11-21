// Tailwind CSS v3 build for the Laravel views (layouts/app.blade.php loads public/css/site.css).
// Rebuild after changing classes in views, PHP or JS:  npm run build:css
// The output is committed because the server does not run Node.
// (resources/css/app.css + Vite + Tailwind v4 belong to the unused welcome scaffold and are separate.)

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        '!./resources/views/welcome.blade.php',
        './app/**/*.php',
        './public/js/**/*.js',
        './public/diagram.html',
        './resources/js/**/*.js',
        // $paginator->links() renders Laravel's own Tailwind pagination view.
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/tailwind.blade.php',
    ],
    theme: {
        extend: {
            colors: {
                // DESIGN.md palette: zamrud (logo green) on map paper, kuning fajar as the one accent.
                rs: {
                    ink: '#14231d',
                    muted: '#4b5d55',
                    z9: '#053b30',
                    z8: '#0b4a3c',
                    z7: '#047857',
                    mist: '#eef4ef',
                    paper: '#f6f4ec',
                    line: '#dedbcd',
                    sun: '#FCD34D',
                },
                primary: {
                    50: '#ecfdf5',
                    100: '#d1fae5',
                    200: '#a7f3d0',
                    300: '#6ee7b7',
                    400: '#34d399',
                    500: '#10b981',
                    600: '#059669',
                    700: '#047857',
                    800: '#065f46',
                    900: '#064e3b',
                },
                mountain: {
                    50: '#f0fdfa',
                    100: '#ccfbf1',
                    200: '#99f6e4',
                    300: '#5eead4',
                    400: '#2dd4bf',
                    500: '#14b8a6',
                    600: '#0d9488',
                    700: '#0f766e',
                    800: '#115e59',
                    900: '#134e4a',
                },
            },
            fontFamily: {
                sans: ['Inter', 'system-ui', 'sans-serif'],
                display: ['"Barlow Condensed"', '"Roboto Condensed"', '"Arial Narrow"', 'sans-serif'],
            },
            backgroundImage: {
                'mountain-gradient': 'linear-gradient(135deg, #065f46 0%, #0f766e 50%, #115e59 100%)',
            },
        },
    },
    plugins: [],
};
