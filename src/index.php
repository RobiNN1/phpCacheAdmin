<?php
declare(strict_types=1);
require_once __DIR__.'/_header.php';
?>
    <section class="px-4 pt-20 pb-10 mx-auto max-w-7xl md:pt-32">
        <div class="mb-16 text-center">
            <h1 class="mb-6 text-4xl font-bold leading-tight sm:text-5xl lg:text-7xl text-balance">
                The Modern GUI for
                <span class="text-transparent bg-clip-text bg-linear-to-r from-redis via-apcu to-memcached">Redis, Memcached, OPCache & APCu</span>
            </h1>
            <p class="mx-auto mb-8 max-w-4xl text-lg leading-relaxed text-gray-600 sm:mb-10 sm:text-xl dark:text-gray-400 text-balance">
                Stop switching between outdated, unmaintained tools. phpCacheAdmin is a blazing-fast, single dashboard that unifies your entire caching layer. Visualize metrics, manage keys, and optimize server performance through one sleek interface.
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
            <img loading="lazy" class="w-full dark:hidden" src="assets/img/preview.webp" alt="phpCacheAdmin Dashboard Preview Light Mode">
            <img loading="lazy" class="hidden w-full dark:block" src="assets/img/preview-dark.webp" alt="phpCacheAdmin Dashboard Preview Dark Mode">
        </div>
    </section>

    <section class="px-4 pt-10 pb-10 mx-auto max-w-7xl" id="benefits">
        <div class="grid grid-cols-1 gap-8 md:grid-cols-3 mt-10">
            <div class="p-6 bg-white rounded-2xl border border-gray-100 shadow-sm dark:bg-slate-900 dark:border-white/5">
                <h3 class="mb-3 text-xl font-bold dark:text-white text-slate-900">All-in-One Solution</h3>
                <p class="text-gray-600 dark:text-gray-400 leading-relaxed">Why maintain separate installations of phpRedisAdmin, opcache-gui, and Memcached dashboards? phpCacheAdmin brings everything under one roof, saving you configuration time and server resources.</p>
            </div>
            <div class="p-6 bg-white rounded-2xl border border-gray-100 shadow-sm dark:bg-slate-900 dark:border-white/5">
                <h3 class="mb-3 text-xl font-bold dark:text-white text-slate-900">Built for Modern Stacks</h3>
                <p class="text-gray-600 dark:text-gray-400 leading-relaxed">Fully compatible with PHP 8.2+ environments. Choose the installation method that fits your needs—run it as a standalone application, deploy it instantly via Docker, or integrate it using Composer.</p>
            </div>
            <div class="p-6 bg-white rounded-2xl border border-gray-100 shadow-sm dark:bg-slate-900 dark:border-white/5">
                <h3 class="mb-3 text-xl font-bold dark:text-white text-slate-900">Enterprise Ready</h3>
                <p class="text-gray-600 dark:text-gray-400 leading-relaxed">Designed to handle complex setups including Redis Clusters and Access Control Lists (ACL). Securely monitor multiple remote servers directly from your centralized command center.</p>
            </div>
        </div>
    </section>

    <section class="px-4 pt-10 pb-10 mx-auto max-w-7xl md:pt-20" id="features">
        <div class="mb-8 text-center">
            <h2 class="mb-4 text-3xl font-bold sm:text-4xl">Supported Cache Systems</h2>
            <p class="mx-auto max-w-2xl text-xl text-muted-foreground">
                Explore the deep integration capabilities for each caching backend.
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
                        <h3 class="text-2xl font-bold leading-none dark:text-white text-slate-900">Redis Dashboard</h3>
                        <p class="mt-1 text-sm font-medium text-slate-500 dark:text-slate-400">Minimum version 4.0 &middot; Phpredis extension or Predis (bundled)</p>
                    </div>
                </div>

                <ul class="grid grid-cols-1 gap-y-3 gap-x-4 md:grid-cols-2">
                    <?php
                    $features = [
                        'Comprehensive server telemetry and health monitoring',
                        'Deep metrics tracking: Fragmentation, Memory, Hit/Miss ratio',
                        'Advanced key management with native CRUD operations',
                        'Seamless data import and export functionality',
                        'Full compatibility with Strings, Hashes, Lists, Sets, and Sorted Sets',
                        'Interactive Slowlog inspector for performance debugging',
                        'Native Redis Cluster topology support',
                        'Secure connections via ACL (Access Control List)',
                        'Performance-optimized key retrieval using SCAN engine',
                        'Quick toggle between multiple configured instances',
                        'Instant database switching',
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
                        <h3 class="text-2xl font-bold leading-none dark:text-white text-slate-900">Memcached Manager</h3>
                        <p class="mt-1 text-sm font-medium text-slate-500 dark:text-slate-400">Minimum version 1.4.31 &middot; No PHP extension required (Uses a custom client)</p>
                    </div>
                </div>

                <ul class="grid grid-cols-1 gap-y-3 gap-x-4 md:grid-cols-2">
                    <?php
                    $features = [
                        'Live monitoring of server health and uptime',
                        'Visualized analytics for cache hit rates and memory allocation',
                        'Direct data manipulation (Create, Read, Update, Delete)',
                        'Reliable key export and import features',
                        'Detailed breakdown of Slabs and Items distribution',
                        'Real-time traffic and command execution statistics',
                        'In-depth request distribution profiling',
                        'Effortless navigation across multiple Memcached nodes',
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
                            <p class="mt-1 text-xs font-medium text-slate-500 dark:text-slate-400">Requires OPcache extension</p>
                        </div>
                    </div>

                    <ul class="grid grid-cols-1 gap-y-3">
                        <?php
                        $features = [
                            'Live RAM consumption graphs',
                            'Examine individually compiled PHP scripts',
                            'Force-invalidate specific files on demand',
                            'Compiler optimization suggestions',
                            'Visual hit/miss execution metrics',
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
                            <p class="mt-1 text-xs font-medium text-slate-500 dark:text-slate-400">Requires APCu extension</p>
                        </div>
                    </div>

                    <ul class="grid grid-cols-1 gap-y-3">
                        <?php
                        $features = [
                            'Complete shared memory overview',
                            'Success rate charting',
                            'Inspect and edit user-cached variables',
                            'Backup cache entries',
                            'Memory fragmentation diagnostics',
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
                            <p class="mt-1 text-xs font-medium text-slate-500 dark:text-slate-400">PHP Stat Cache Monitor</p>
                        </div>
                    </div>

                    <ul class="grid grid-cols-1 gap-y-3">
                        <?php
                        $features = [
                            'Track stat cache buffer limits',
                            'Audit resolved absolute paths',
                            'Manual cache invalidation',
                            'Symlink resolution viewer',
                            'Directory and file type indicators',
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
                Choose the installation method that fits your workflow. No complicated dependencies are required.
            </p>
        </div>

        <div class="overflow-hidden bg-white rounded-3xl border border-gray-100 shadow-xl shadow-gray-200/50 dark:bg-slate-900 dark:border-white/5 dark:shadow-black/20">
            <div class="border-b border-gray-100 bg-gray-50/50 dark:border-white/5 dark:bg-white/5">
                <div class="flex">
                    <button type="button" data-group="install" data-target="manual" class="tab-link active flex-1 py-4 text-sm font-bold text-center border-b-2 border-transparent transition-colors text-gray-500 hover:text-gray-800 hover:bg-gray-50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-white/5 [&.active]:text-blue-600 [&.active]:border-blue-600 dark:[&.active]:text-white">
                        Manual Download
                    </button>
                    <button type="button" data-group="install" data-target="docker" class="tab-link flex-1 py-4 text-sm font-bold text-center border-b-2 border-transparent transition-colors text-gray-500 hover:text-gray-800 hover:bg-gray-50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-white/5 [&.active]:text-blue-600 [&.active]:border-blue-600 dark:[&.active]:text-white">
                        Docker Image
                    </button>
                    <button type="button" data-group="install" data-target="composer" class="tab-link flex-1 py-4 text-sm font-bold text-center border-b-2 border-transparent transition-colors text-gray-500 hover:text-gray-800 hover:bg-gray-50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-white/5 [&.active]:text-blue-600 [&.active]:border-blue-600 dark:[&.active]:text-white">
                        Composer Require
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
                            <code class="px-1 rounded bg-emerald-200/50 dark:bg-emerald-900/50">/tmp/twig</code> folder to clear precompiled templates.
                        </p>
                    </div>
                </div>

                <div id="docker" class="hidden space-y-6 tab-content">
                    <div>
                        <h3 class="mb-2 text-lg font-bold dark:text-white text-slate-900">Run with a single command</h3>
                        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">The fastest way to get started. Pulls the lightweight image and exposes the interface on port 8080.</p>

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
                        <p class="relative mb-6 text-sm text-gray-600 dark:text-gray-400">Seamlessly integrate the dashboard into your existing PHP application or framework.</p>

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

    <section class="px-4 pt-10 pb-20 mx-auto max-w-4xl" id="faq">
        <div class="mb-10 text-center">
            <h2 class="text-3xl font-bold sm:text-4xl dark:text-white text-slate-900">Frequently Asked Questions</h2>
        </div>
        <div class="space-y-6">
            <div class="p-6 bg-white rounded-2xl border border-gray-100 dark:bg-slate-900 dark:border-white/5">
                <h3 class="text-lg font-bold dark:text-white text-slate-900">Does phpCacheAdmin require a database?</h3>
                <p class="mt-2 text-gray-600 dark:text-gray-400 text-sm">No external database like MySQL is needed. It communicates directly with your caching servers. Historical metrics are saved locally using PHP's native SQLite3 extension.</p>
            </div>
            <div class="p-6 bg-white rounded-2xl border border-gray-100 dark:bg-slate-900 dark:border-white/5">
                <h3 class="text-lg font-bold dark:text-white text-slate-900">Can I manage multiple servers simultaneously?</h3>
                <p class="mt-2 text-gray-600 dark:text-gray-400 text-sm">Yes, you can configure an unlimited number of Redis and Memcached instances in your configuration file and switch between them directly from the navigation bar.</p>
            </div>
            <div class="p-6 bg-white rounded-2xl border border-gray-100 dark:bg-slate-900 dark:border-white/5">
                <h3 class="text-lg font-bold dark:text-white text-slate-900">Is it safe to use in a production environment?</h3>
                <p class="mt-2 text-gray-600 dark:text-gray-400 text-sm">Yes, but a proper setup is required. You must enable authentication in the configuration file or secure the dashboard behind your own security layer (such as a reverse proxy). Additionally, it supports Redis ACL and configurable SCAN limits to prevent blocking the main Redis thread on large databases.</p>
            </div>
            <div class="p-6 bg-white rounded-2xl border border-gray-100 dark:bg-slate-900 dark:border-white/5">
                <h3 class="text-lg font-bold dark:text-white text-slate-900">How do I fix "Fatal error: Allowed memory size exhausted"?</h3>
                <p class="mt-2 text-gray-600 dark:text-gray-400 text-sm">This typically happens when you have millions of keys in Redis and limited PHP RAM, because the tool uses the
                    <code>KEYS</code> command by default. To resolve this, open your configuration file and enable the
                    <code>SCAN</code> command (e.g., set <code>PCA_REDIS_0_SCANSIZE</code> or uncomment
                    <code>scansize</code> in <code>config.php</code>).</p>
            </div>
            <div class="p-6 bg-white rounded-2xl border border-gray-100 dark:bg-slate-900 dark:border-white/5">
                <h3 class="text-lg font-bold dark:text-white text-slate-900">Can I collect metrics in the background?</h3>
                <p class="mt-2 text-gray-600 dark:text-gray-400 text-sm">Yes, you can collect historical data even when the dashboard is not open in your browser by setting up a cronjob. Trigger the metrics endpoint for your desired cache periodically, for example:
                    <code class="py-0.5 px-1 text-xs bg-gray-100 rounded dark:bg-black/40">curl -s "https://example.com/?dashboard=redis&amp;server=0&amp;ajax&amp;metrics" &gt; /dev/null</code>.
                </p>
            </div>
        </div>
    </section>
<?php
require_once __DIR__.'/_footer.php';
