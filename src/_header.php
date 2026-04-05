<?php
declare(strict_types=1);

if (!function_exists('svg')) {
    function svg(string $icon, ?int $size = 16, ?string $class = null): string {
        $file = is_file($icon) ? $icon : __DIR__.'/../assets/img/icons/'.$icon.'.svg';
        $content = is_file($file) ? trim(file_get_contents($file)) : $icon;

        preg_match('~<svg([^<>]*)>~', $content, $attributes);

        $size_attr = $size !== null ? ' width="'.$size.'" height="'.$size.'"' : '';
        $class_attr = $class !== null ? ' class="'.$class.'"' : '';
        $svg = preg_replace('~<svg([^<>]*)>~', '<svg'.($attributes[1] ?? '').$size_attr.$class_attr.'>', $content);
        $svg = preg_replace('/\s+/', ' ', $svg);

        return str_replace("\n", '', $svg);
    }
}

$page_title = $page_title ?? 'phpCacheAdmin - Modern GUI for Redis, Memcached, OPCache & APCu';
$page_desc = $page_desc ?? 'The ultimate web dashboard for Redis, Memcached, APCu, OPCache, and Realpath. A modern, docker-ready alternative to phpRedisAdmin and opcache-gui with Cluster & ACL support.';
$canonical_url = $canonical_url ?? 'https://phpcacheadmin.com/';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <meta name="description" content="<?php echo $page_desc; ?>">
    <meta name="keywords" content="phpCacheAdmin, Redis GUI, Memcached Admin, OPCache GUI, APCu Dashboard, Realpath Cache, phpRedisAdmin alternative, Redis Cluster, Docker, PHP cache manager">
    <meta name="robots" content="index, follow">
    <meta name="author" content="RobiNN1">
    <link rel="canonical" href="<?php echo $canonical_url; ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo $canonical_url; ?>">
    <meta property="og:title" content="<?php echo $page_title; ?>">
    <meta property="og:description" content="<?php echo $page_desc; ?>">
    <meta property="og:image" content="https://phpcacheadmin.com/assets/og-image.jpg">
    <meta property="og:site_name" content="phpCacheAdmin">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon.png">
    <meta name="theme-color" content="#ffffff">
    <base href="http://pcadocs.host/">
    <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo filemtime(__DIR__.'/../assets/css/styles.css'); ?>">
    <script>
        const theme = localStorage.getItem('theme') || 'system';
        let current_theme = theme;

        if (theme === 'system') {
            current_theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.setAttribute('color-theme', 'system');
        } else {
            document.documentElement.setAttribute('color-theme', theme);
        }

        document.documentElement.classList.toggle('dark', current_theme === 'dark');
    </script>
    <script type="application/ld+json">
        {
          "@context": "https://schema.org",
          "@type": "SoftwareApplication",
          "name": "phpCacheAdmin",
          "operatingSystem": "Linux, Windows, macOS",
          "applicationCategory": "DeveloperApplication",
          "offers": {
            "@type": "Offer",
            "price": "0",
            "priceCurrency": "USD"
          },
          "description": "<?php echo $page_desc; ?>",
      "url": "<?php echo $canonical_url; ?>",
      "author": {
        "@type": "Person",
        "name": "RobiNN1"
      }
    }
    </script>
</head>
<body class="overflow-x-hidden antialiased transition-colors duration-300 bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-300">
<div class="overflow-hidden fixed inset-0 z-0 pointer-events-none">
    <div class="absolute -top-40 -right-40 w-96 h-96 rounded-full bg-sky-500/20 blur-3xl mix-blend-multiply dark:mix-blend-screen dark:bg-sky-500/10"></div>
    <div class="absolute -left-20 top-40 w-72 h-72 rounded-full bg-redis/20 blur-3xl mix-blend-multiply dark:mix-blend-screen dark:bg-redis/10"></div>
    <div class="absolute right-20 bottom-20 w-80 h-80 rounded-full bg-memcached/20 blur-3xl mix-blend-multiply dark:mix-blend-screen dark:bg-memcached/10"></div>
</div>

<nav class="fixed top-0 z-50 w-full border-b border-gray-100 bg-white/50 backdrop-blur-xl dark:border-b-white/5 dark:bg-slate-950/70">
    <div class="px-4 mx-auto max-w-7xl">
        <div class="flex flex-wrap justify-between items-center lg:text-xl">
            <a class="inline-block py-3" href="/" aria-label="Link to this site">
                <?php echo svg('../logo', null, 'h-5 md:h-10 w-auto'); ?>
            </a>

            <div class="flex items-center py-3 md:hidden">
                <button id="toggle-menu" type="button" class="text-gray-600 dark:text-gray-300">
                    <?php echo svg('menu', 24); ?>
                    <span class="sr-only">Toggle menu</span>
                </button>
            </div>

            <div class="hidden order-last w-full md:flex md:w-auto md:order-0" id="menu">
                <div class="flex flex-col gap-6 items-center py-4 w-full md:flex-row md:gap-8 md:py-0">
                    <div class="flex gap-3 justify-center">
                        <div class="flex p-1 gap-1 h-10 items-center rounded-lg bg-gray-100 dark:bg-white/5 border border-transparent dark:border-white/5 [&>.active]:bg-white dark:[&>.active]:bg-slate-700 [&>.active]:text-gray-900 dark:[&>.active]:text-white [&>.active]:shadow-sm">
                            <button class="flex justify-center items-center w-8 h-8 text-gray-500 rounded-md transition-all cursor-pointer dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200" type="button" data-theme="light" title="Light">
                                <?php echo svg('sun'); ?>
                            </button>
                            <button class="flex justify-center items-center w-8 h-8 text-gray-500 rounded-md transition-all cursor-pointer dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200" type="button" data-theme="dark" title="Dark">
                                <?php echo svg('moon'); ?>
                            </button>
                            <button class="flex justify-center items-center w-8 h-8 text-gray-500 rounded-md transition-all cursor-pointer dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200" type="button" data-theme="system" title="System">
                                <?php echo svg('system'); ?>
                            </button>
                        </div>

                        <a href="https://github.com/RobiNN1/phpCacheAdmin" target="_blank" rel="noopener noreferrer" class="flex gap-2 justify-center items-center px-4 h-10 text-sm font-semibold text-white bg-gray-900 rounded-lg transition-opacity dark:text-gray-900 dark:bg-white hover:opacity-90">
                            <?php echo svg('github', 20); ?>
                            <span>GitHub</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<main class="relative z-10">
