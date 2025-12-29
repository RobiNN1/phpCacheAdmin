<?php
declare(strict_types=1);

function svg(string $icon, ?int $size = 16, ?string $class = null): string {
    $file = is_file($icon) ? $icon : __DIR__.'/assets/img/icons/'.$icon.'.svg';
    $content = is_file($file) ? trim(file_get_contents($file)) : $icon;

    preg_match('~<svg([^<>]*)>~', $content, $attributes);

    $size_attr = $size !== null ? ' width="'.$size.'" height="'.$size.'"' : '';
    $class_attr = $class !== null ? ' class="'.$class.'"' : '';
    $svg = preg_replace('~<svg([^<>]*)>~', '<svg'.($attributes[1] ?? '').$size_attr.$class_attr.'>', $content);
    $svg = preg_replace('/\s+/', ' ', $svg);

    return str_replace("\n", '', $svg);
}

ob_start(static function (string $html): string {
    $html = preg_replace('/\s+/', ' ', $html);

    return preg_replace('/<!--(?!\[if).*?-->/', '', $html);
});

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>phpCacheAdmin - Modern GUI for Redis, Memcached, OPCache & APCu</title>
    <meta name="description" content="The ultimate web dashboard for Redis, Memcached, APCu, OPCache, and Realpath. A modern, docker-ready alternative to phpRedisAdmin and opcache-gui with Cluster & ACL support.">
    <meta name="keywords" content="phpCacheAdmin, Redis GUI, Memcached Admin, OPCache GUI, APCu Dashboard, Realpath Cache, phpRedisAdmin alternative, Redis Cluster, Docker, PHP cache manager">
    <meta name="robots" content="index, follow">
    <meta name="author" content="RobiNN1">
    <link rel="canonical" href="https://phpcacheadmin.com/">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://phpcacheadmin.com/">
    <meta property="og:title" content="phpCacheAdmin - Modern GUI for Redis, Memcached, OPCache & APCu">
    <meta property="og:description" content="The ultimate web dashboard for Redis, Memcached, APCu, OPCache, and Realpath. A modern, docker-ready alternative to phpRedisAdmin and opcache-gui with Cluster & ACL support.">
    <meta property="og:image" content="https://phpcacheadmin.com/assets/og-image.jpg">
    <meta property="og:site_name" content="phpCacheAdmin">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon.png">
    <meta name="theme-color" content="#ffffff">
    <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo filemtime(__DIR__.'/assets/css/styles.css'); ?>">
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
                    <?php /* ?><div class="flex flex-col gap-4 text-center md:flex-row md:gap-8">
                        <?php
                        $links = [
                            'Features',
                            'Installation',
                            'Configuration',
                        ];
                        foreach ($links as $link) {
                            $url = strtolower(str_replace(' ', '-', $link));
                            echo '<a href="#'.$url.'" class="font-medium text-gray-600 transition-colors dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-200">'.$link.'</a>';
                        }
                        ?>
                    </div><?php */ ?>

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
    <section class="px-4 pt-20 pb-10 mx-auto max-w-7xl md:pt-32">
        <div class="mb-16 text-center">
            <h1 class="mb-6 text-4xl font-bold leading-tight sm:text-5xl lg:text-7xl text-balance">
                The Modern GUI for
                <span class="text-transparent bg-clip-text bg-linear-to-r from-redis via-apcu to-memcached">Redis, Memcached, OPCache & APCu</span>
            </h1>
            <p class="mx-auto mb-8 max-w-4xl text-lg leading-relaxed text-gray-600 sm:mb-10 sm:text-xl dark:text-gray-400 text-balance">
                A powerful, extensible admin dashboard for Redis, Memcached, OPCache and APCu.
                Visualize real-time metrics, manage keys and monitor server performance through a modern web GUI.
            </p>

            <div class="flex flex-wrap gap-4 justify-center">
                <a href="#installation" class="py-3 px-6 text-base font-bold text-white bg-blue-600 rounded-2xl shadow-lg transition-colors sm:py-4 sm:px-8 sm:text-lg dark:bg-blue-800 hover:bg-blue-700 shadow-blue-500/25 dark:hover:bg-blue-700 dark:shadow-blue-900/20">
                    Get Started
                </a>
                <a href="https://github.com/RobiNN1/phpCacheAdmin" target="_blank" rel="noopener noreferrer" class="flex gap-3 items-center py-3 px-6 text-base font-bold text-gray-900 bg-white rounded-2xl border-2 border-gray-100 shadow-xl transition-colors sm:py-4 sm:px-8 sm:text-lg dark:text-white hover:bg-gray-50 dark:bg-white/10 dark:hover:bg-white/20 dark:border-white/5">
                    <?php echo svg('github', 20); ?>
                    <span>Star on GitHub</span>
                </a>
            </div>

            <div class="flex flex-wrap gap-3 justify-center mt-8 text-sm font-medium text-gray-600 sm:gap-6 dark:text-gray-300">
                <div class="flex gap-2 items-center py-1.5 px-3 bg-white rounded-full border border-gray-200 shadow-sm dark:bg-white/5 dark:border-white/10">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span>
                    Zero Dependencies
                </div>
                <div class="flex gap-2 items-center py-1.5 px-3 bg-white rounded-full border border-gray-200 shadow-sm dark:bg-white/5 dark:border-white/10">
                    <span class="w-1.5 h-1.5 bg-blue-500 rounded-full"></span>
                    Docker Ready
                </div>
                <div class="flex gap-2 items-center py-1.5 px-3 bg-white rounded-full border border-gray-200 shadow-sm dark:bg-white/5 dark:border-white/10">
                    <span class="w-1.5 h-1.5 bg-purple-500 rounded-full"></span>
                    PHP 8.2+
                </div>
            </div>
        </div>

        <div class="overflow-hidden mt-16 rounded-lg border-2 border-gray-200 shadow-2xl md:rounded-2xl dark:border-white/10 dark:shadow-black/50">
            <img loading="lazy" class="w-full dark:hidden" src="assets/img/preview.webp" alt="phpCacheAdmin Dashboard Preview">
            <img loading="lazy" class="hidden w-full dark:block" src="assets/img/preview-dark.webp" alt="phpCacheAdmin Dashboard Preview">
        </div>
    </section>

    <section class="px-4 pt-10 pb-10 mx-auto max-w-7xl md:pt-20" id="features">
        <div class="mb-8 text-center">
            <h2 class="mb-4 text-3xl font-bold sm:text-4xl">Supported Cache Systems</h2>
            <p class="mx-auto max-w-2xl text-xl text-muted-foreground">
                Manage all your PHP caching backends from one beautiful interface
            </p>
        </div>

        <div class="flex flex-wrap gap-3 justify-center">
            <?php
            $links = ['Redis', 'Memcached', 'PHP Caches'];

            $color_map = [
                'redis'      => '[&.active]:bg-redis [&.active]:shadow-redis/30',
                'memcached'  => '[&.active]:bg-memcached [&.active]:shadow-memcached/30',
                'php-caches' => '[&.active]:bg-indigo-600 [&.active]:shadow-indigo-500/30',
            ];

            foreach ($links as $index => $link) {
                $slug = strtolower(str_replace(' ', '-', $link));
                $is_active = $index === 0 ? ' active' : '';
                $base = 'tab-link cursor-pointer inline-flex items-center px-6 py-2.5 rounded-full font-bold text-sm transition-all duration-300 bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-400 dark:hover:bg-white/10 [&.active]:text-white [&.active]:shadow-lg [&.active]:scale-105';
                $active_color = $color_map[$slug] ?? '[&.active]:bg-gray-600';
                echo '<button type="button" data-group="features" data-target="'.$slug.'" class="'.$base.' '.$active_color.$is_active.'">'.$link.'</button>';
            }
            ?>
        </div>

        <div id="redis" class="mt-8 tab-content">
            <div class="overflow-hidden relative p-8 bg-white rounded-2xl border-t-4 shadow-xl shadow-gray-200/50 border-redis dark:bg-slate-900 dark:shadow-black/20">
                <div class="absolute -top-6 -right-6 w-32 h-32 rounded-full pointer-events-none bg-redis/30 blur-3xl"></div>

                <div class="flex relative gap-4 items-center mb-8">
                    <div class="flex justify-center items-center w-14 h-14 rounded-xl bg-redis/10 text-redis shrink-0">
                        <?php echo svg('redis', 32); ?>
                    </div>
                    <div>
                        <h3 class="text-2xl font-bold leading-none dark:text-white text-slate-900">Redis</h3>
                        <p class="mt-1 text-sm font-medium text-slate-500 dark:text-slate-400">Minimum version 4.0 &middot; Phpredis extension or Predis (bundled)</p>
                    </div>
                </div>

                <ul class="grid grid-cols-1 gap-y-3 gap-x-4 md:grid-cols-2">
                    <?php
                    $features = [
                        'Server statistics overview',
                        'Metrics: Memory, Fragmentation, Hit rate, Commands per second',
                        'Key Management (full CRUD)',
                        'Import & Export keys',
                        'Supports all Redis data types',
                        'Slowlog monitoring',
                        'Cluster Mode support',
                        'ACL (Access Control List) support',
                        'Smart retrieval (SCAN & KEYS support)',
                        'Multi-server switching',
                        'Database switching',
                    ];

                    foreach ($features as $feature) {
                        echo '<li class="flex gap-2.5 items-start text-sm font-medium leading-tight text-slate-700 dark:text-slate-300">';
                        echo svg('check', 16, 'text-redis shrink-0');
                        echo '<span>'.$feature.'</span>';
                        echo '</li>';
                    }
                    ?>
                </ul>
            </div>
        </div>

        <div id="memcached" class="hidden mt-8 tab-content">
            <div class="overflow-hidden relative p-8 bg-white rounded-2xl border-t-4 shadow-xl shadow-gray-200/50 border-memcached dark:bg-slate-900 dark:shadow-black/20">
                <div class="absolute -top-6 -right-6 w-32 h-32 rounded-full pointer-events-none bg-memcached/30 blur-3xl"></div>

                <div class="flex relative gap-4 items-center mb-8">
                    <div class="flex justify-center items-center w-14 h-14 rounded-xl bg-memcached/10 text-memcached shrink-0">
                        <?php echo svg('memcached', 32); ?>
                    </div>
                    <div>
                        <h3 class="text-2xl font-bold leading-none dark:text-white text-slate-900">Memcached</h3>
                        <p class="mt-1 text-sm font-medium text-slate-500 dark:text-slate-400">Minimum version 1.4.31 &middot; No extension required</p>
                    </div>
                </div>

                <ul class="grid grid-cols-1 gap-y-3 gap-x-4 md:grid-cols-2">
                    <?php
                    $features = [
                        'Server statistics overview',
                        'Metrics: Hit rate, Memory, Requests',
                        'Key Management (full CRUD)',
                        'Import & Export keys',
                        'Slabs & Items information',
                        'Commands & Traffic statistics',
                        'Request distribution details',
                        'Multi-server switching',
                    ];

                    foreach ($features as $feature) {
                        echo '<li class="flex gap-2.5 items-start text-sm font-medium leading-tight text-slate-700 dark:text-slate-300">';
                        echo svg('check', 16, 'text-memcached shrink-0');
                        echo '<span>'.$feature.'</span>';
                        echo '</li>';
                    }
                    ?>
                </ul>
            </div>
        </div>

        <div id="php-caches" class="hidden mt-8 tab-content">
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                <div class="overflow-hidden relative p-6 bg-white rounded-2xl border-t-4 shadow-xl shadow-gray-200/50 border-opcache dark:bg-slate-900 dark:shadow-black/20">
                    <div class="absolute -top-6 -right-6 w-24 h-24 rounded-full pointer-events-none bg-opcache/20 blur-2xl"></div>

                    <div class="flex relative gap-4 items-center mb-6">
                        <div class="flex justify-center items-center w-12 h-12 rounded-xl bg-opcache/10 text-opcache shrink-0">
                            <?php echo svg('opcache', 24); ?>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold leading-none dark:text-white text-slate-900">OPCache</h3>
                            <p class="mt-1 text-xs font-medium text-slate-500 dark:text-slate-400">Extension required</p>
                        </div>
                    </div>

                    <ul class="grid grid-cols-1 gap-y-3">
                        <?php
                        $features = [
                            'Memory usage statistics',
                            'View all cached scripts',
                            'Invalidate specific scripts',
                            'Optimization insights',
                            'Hit & Miss metrics',
                        ];
                        foreach ($features as $feature) {
                            echo '<li class="flex gap-2.5 items-start text-sm font-medium leading-tight text-slate-700 dark:text-slate-300">';
                            echo svg('check', 16, 'text-opcache shrink-0');
                            echo '<span>'.$feature.'</span>';
                            echo '</li>';
                        }
                        ?>
                    </ul>
                </div>

                <div class="overflow-hidden relative p-6 bg-white rounded-2xl border-t-4 shadow-xl shadow-gray-200/50 border-apcu dark:bg-slate-900 dark:shadow-black/20">
                    <div class="absolute -top-6 -right-6 w-24 h-24 rounded-full pointer-events-none bg-apcu/20 blur-2xl"></div>

                    <div class="flex relative gap-4 items-center mb-6">
                        <div class="flex justify-center items-center w-12 h-12 rounded-xl bg-apcu/10 text-apcu shrink-0">
                            <?php echo svg('apcu', 24); ?>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold leading-none dark:text-white text-slate-900">APCu</h3>
                            <p class="mt-1 text-xs font-medium text-slate-500 dark:text-slate-400">Extension required</p>
                        </div>
                    </div>

                    <ul class="grid grid-cols-1 gap-y-3">
                        <?php
                        $features = [
                            'Full memory breakdown',
                            'Hit & Miss visualization',
                            'Key Management (CRUD)',
                            'Import & Export',
                            'Fragmentation analysis',
                        ];
                        foreach ($features as $feature) {
                            echo '<li class="flex gap-2.5 items-start text-sm font-medium leading-tight text-slate-700 dark:text-slate-300">';
                            echo svg('check', 16, 'text-apcu shrink-0');
                            echo '<span>'.$feature.'</span>';
                            echo '</li>';
                        }
                        ?>
                    </ul>
                </div>

                <div class="overflow-hidden relative p-6 bg-white rounded-2xl border-t-4 shadow-xl shadow-gray-200/50 border-realpath dark:bg-slate-900 dark:shadow-black/20">
                    <div class="absolute -top-6 -right-6 w-24 h-24 rounded-full pointer-events-none bg-realpath/20 blur-2xl"></div>

                    <div class="flex relative gap-4 items-center mb-6">
                        <div class="flex justify-center items-center w-12 h-12 rounded-xl bg-realpath/10 text-realpath shrink-0">
                            <?php echo svg('realpath', 24); ?>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold leading-none dark:text-white text-slate-900">Realpath</h3>
                            <p class="mt-1 text-xs font-medium text-slate-500 dark:text-slate-400">Stat Cache Monitor</p>
                        </div>
                    </div>

                    <ul class="grid grid-cols-1 gap-y-3">
                        <?php
                        $features = [
                            'Cache memory usage',
                            'View cached file paths',
                            'Clear/Invalidate cache',
                            'Path mapping inspection',
                            'Dir vs File distinction',
                        ];
                        foreach ($features as $feature) {
                            echo '<li class="flex gap-2.5 items-start text-sm font-medium leading-tight text-slate-700 dark:text-slate-300">';
                            echo svg('check', 16, 'text-realpath shrink-0');
                            echo '<span>'.$feature.'</span>';
                            echo '</li>';
                        }
                        ?>
                    </ul>
                </div>

            </div>
        </div>
    </section>

    <section id="installation" class="py-20 px-4 mx-auto max-w-4xl">
        <div class="mb-10 text-center">
            <h2 class="mb-4 text-3xl font-bold sm:text-4xl dark:text-white text-slate-900">Get Started in Seconds</h2>
            <p class="text-lg text-gray-600 dark:text-gray-400">
                Choose the installation method that fits your workflow.
            </p>
        </div>

        <div class="overflow-hidden bg-white rounded-3xl border border-gray-100 shadow-xl shadow-gray-200/50 dark:bg-slate-900 dark:border-white/5 dark:shadow-black/20">
            <div class="border-b border-gray-100 bg-gray-50/50 dark:border-white/5 dark:bg-white/5">
                <div class="flex">
                    <button type="button" data-group="install" data-target="manual" class="tab-link active flex-1 py-4 text-sm font-bold text-center border-b-2 border-transparent transition-colors text-gray-500 hover:text-gray-800 hover:bg-gray-50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-white/5 [&.active]:text-blue-600 [&.active]:border-blue-600 dark:[&.active]:text-white">
                        Manual
                    </button>
                    <button type="button" data-group="install" data-target="docker" class="tab-link flex-1 py-4 text-sm font-bold text-center border-b-2 border-transparent transition-colors text-gray-500 hover:text-gray-800 hover:bg-gray-50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-white/5 [&.active]:text-blue-600 [&.active]:border-blue-600 dark:[&.active]:text-white">
                        Docker
                    </button>
                    <button type="button" data-group="install" data-target="composer" class="tab-link flex-1 py-4 text-sm font-bold text-center border-b-2 border-transparent transition-colors text-gray-500 hover:text-gray-800 hover:bg-gray-50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-white/5 [&.active]:text-blue-600 [&.active]:border-blue-600 dark:[&.active]:text-white">
                        Composer
                    </button>
                </div>
            </div>

            <div class="p-6 sm:p-10">
                <div id="manual" class="space-y-6 tab-content">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div class="relative p-6 bg-gray-50 rounded-2xl border border-gray-100 transition-colors dark:bg-white/5 dark:border-white/5">
                            <div class="absolute top-6 right-6 text-6xl font-black leading-none text-gray-200 select-none dark:text-white/5">1</div>
                            <h3 class="relative mb-2 text-lg font-bold dark:text-white text-slate-900">Download</h3>
                            <p class="relative mb-6 text-sm text-gray-600 dark:text-gray-400">Get the latest release zip file directly from GitHub.</p>
                            <a href="https://github.com/RobiNN1/phpCacheAdmin/releases" target="_blank" rel="noopener noreferrer" class="py-2.5 px-4 w-full text-sm font-bold text-white bg-blue-600 rounded-lg shadow-lg transition-colors hover:bg-blue-700 shadow-blue-500/20">
                                Go to Releases
                            </a>
                        </div>

                        <div class="relative p-6 bg-gray-50 rounded-2xl border border-gray-100 dark:bg-white/5 dark:border-white/5">
                            <div class="absolute top-6 right-6 text-6xl font-black leading-none text-gray-200 select-none dark:text-white/5">2</div>
                            <h3 class="relative mb-2 text-lg font-bold dark:text-white text-slate-900">Unzip & Config</h3>
                            <p class="relative text-sm text-gray-600 dark:text-gray-400">
                                Unzip the folder to your web directory. Optionally copy
                                <code class="py-0.5 px-1 text-xs bg-gray-200 rounded dark:bg-black/40">config.dist.php</code> to
                                <code class="py-0.5 px-1 text-xs bg-gray-200 rounded dark:bg-black/40">config.php</code>.
                            </p>
                        </div>
                    </div>

                    <div class="flex gap-3 p-4 text-sm text-emerald-800 bg-emerald-50 rounded-lg border border-emerald-100 dark:text-emerald-200 dark:bg-emerald-500/10 dark:border-emerald-500/20">
                        <p><strong>Updating?</strong> Replace the files and delete the
                            <code class="px-1 rounded bg-emerald-200/50 dark:bg-emerald-900/50">/tmp/twig</code> folder.
                        </p>
                    </div>
                </div>

                <div id="docker" class="hidden space-y-6 tab-content">
                    <div>
                        <h3 class="mb-2 text-lg font-bold dark:text-white text-slate-900">Run with a single command</h3>
                        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">The fastest way to get started. Runs on port 8080 by default.</p>

                        <div class="overflow-x-auto relative p-5 rounded-xl border shadow-inner bg-slate-950 border-white/10">
                            <code class="font-mono text-sm leading-relaxed text-blue-100">
                                <span class="text-purple-400">docker</span> run -p
                                <span class="text-emerald-400">8080:80</span> -d --name phpcacheadmin
                                -e <span class="text-yellow-300">"PCA_REDIS_0_HOST=redis_host"</span>
                                -e <span class="text-yellow-300">"PCA_REDIS_0_PORT=6379"</span>
                                -e <span class="text-yellow-300">"PCA_MEMCACHED_0_HOST=memcached_host"</span>
                                -e <span class="text-yellow-300">"PCA_MEMCACHED_0_PORT=11211"</span>
                                robinn/phpcacheadmin
                            </code>
                        </div>

                        <div class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                            Need more configuration? Check out the
                            <a href="https://github.com/RobiNN1/phpCacheAdmin?tab=readme-ov-file#environment-variables" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-600 dark:text-blue-400 hover:underline">
                                Environment Variables documentation
                            </a>
                        </div>
                    </div>
                </div>

                <div id="composer" class="hidden space-y-8 tab-content">
                    <div>
                        <h3 class="mb-3 font-bold dark:text-white text-slate-900">Install via Composer</h3>
                        <p class="relative mb-6 text-sm text-gray-600 dark:text-gray-400">Integrate into existing PHP projects.</p>

                        <div class="p-4 rounded-lg border bg-slate-950 border-white/10">
                            <code class="font-mono text-sm text-gray-300">
                                <span class="text-purple-400">composer</span> require robinn/phpcacheadmin
                            </code>
                        </div>
                    </div>

                    <div>
                        <h3 class="mb-3 font-bold dark:text-white text-slate-900">Embed in your application</h3>
                        <div class="overflow-x-auto p-4 rounded-lg border bg-slate-950 border-white/10">
                            <code class="font-mono text-sm leading-relaxed text-gray-300">
                                <span class="text-gray-500">// Copy config file from the vendor folder or GitHub.</span><br>
                                <span class="text-gray-500">// Set config path</span><br>
                                <span class="text-yellow-100">\RobiNN\Pca\Config</span>::setConfigPath(__DIR__.<span class="text-green-400">'/pca.php'</span>);<br>
                                <span class="text-gray-500">// Render dashboard</span><br>
                                <span class="text-purple-400">echo</span> (<span class="text-blue-400">new</span>
                                <span class="text-yellow-100">\RobiNN\Pca\Admin</span>())-&gt;render(<span class="text-blue-400">false</span>);
                            </code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="py-10 px-4 mx-auto max-w-7xl">
    <div class="flex flex-col gap-4 justify-between items-center pt-8 border-t border-gray-200 md:flex-row dark:border-gray-800">
        <div class="text-sm text-gray-600 dark:text-gray-400">
            &copy; <span id="year"></span> phpCacheAdmin. Open source under MIT License.
        </div>
        <div class="text-sm text-gray-600 dark:text-gray-400">
            Made by
            <a href="https://github.com/RobiNN1" target="_blank" rel="noopener noreferrer" class="font-semibold transition-colors hover:text-gray-900 dark:hover:text-white">RobiNN1</a>
        </div>
    </div>
</footer>
<script>document.getElementById('year').innerText = String(new Date().getFullYear());</script>
<script src="assets/js/scripts.js?v=<?php echo filemtime(__DIR__.'/assets/js/scripts.js'); ?>"></script>
</body>
</html><?php
// header('Content-type: plain/text');
ob_end_flush();
