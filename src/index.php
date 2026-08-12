<?php
declare(strict_types=1);
require_once __DIR__.'/_header.php';
?>
    <section class="px-4 pt-20 pb-10 mx-auto max-w-7xl md:pt-32">
        <div class="mb-16 text-center">
            <h1 class="mb-6 text-4xl font-semibold tracking-tight leading-[1.05] sm:text-5xl lg:text-6xl text-balance">
                The Modern GUI for
                <span class="text-redis">Redis</span>, <span class="text-memcached">Memcached</span>,
                <span class="text-opcache">OPCache</span> & <span class="text-apcu">APCu</span>
            </h1>
            <p class="mx-auto mb-8 max-w-4xl text-lg leading-relaxed text-body sm:mb-10 sm:text-xl dark:text-gray-400 text-balance">
                Stop switching between outdated, unmaintained tools. phpCacheAdmin is a blazing-fast, single dashboard that unifies your entire caching layer. Visualize metrics, manage keys, and optimize server performance through one sleek interface.
            </p>

            <div class="flex flex-wrap gap-4 justify-center">
                <a href="#installation" class="inline-flex gap-2 items-center py-3.5 px-6 text-base font-semibold text-white bg-blue-700 rounded-btn shadow-btn transition-colors hover:bg-blue-800 dark:bg-blue-700 dark:hover:bg-blue-600">
                    Get Started
                </a>
                <a href="https://github.com/RobiNN1/phpCacheAdmin" target="_blank" rel="noopener noreferrer" class="inline-flex gap-2.5 items-center py-3.5 px-6 text-base font-semibold text-ink bg-white rounded-btn border border-line shadow-btn transition-colors hover:bg-surface hover:border-gray-300 dark:text-white dark:bg-white/5 dark:border-white/10 dark:hover:bg-white/10">
                    <?php echo svg('github', 20); ?>
                    <span>Star on GitHub</span>
                </a>
            </div>

            <div class="flex flex-wrap gap-3 justify-center mt-8 text-sm font-medium text-body sm:gap-6 dark:text-gray-300">
                <div class="flex gap-2 items-center py-1.5 px-3.5 bg-white rounded-full border border-line dark:bg-white/5 dark:border-white/10">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span>
                    Zero Dependencies
                </div>
                <div class="flex gap-2 items-center py-1.5 px-3.5 bg-white rounded-full border border-line dark:bg-white/5 dark:border-white/10">
                    <span class="w-1.5 h-1.5 bg-blue-500 rounded-full"></span>
                    Docker Ready
                </div>
                <div class="flex gap-2 items-center py-1.5 px-3.5 bg-white rounded-full border border-line dark:bg-white/5 dark:border-white/10">
                    <span class="w-1.5 h-1.5 bg-purple-500 rounded-full"></span>
                    PHP 8.2+
                </div>
            </div>
        </div>

        <?php $previews = ['redis' => 'Redis', 'memcached' => 'Memcached', 'opcache' => 'OPCache']; ?>
        <div class="overflow-hidden mt-16 text-left rounded-card border border-line shadow-lift dark:border-ink-line dark:shadow-none">
            <div class="flex flex-wrap gap-3 justify-between items-center py-3 px-4 border-b bg-surface border-line-soft dark:bg-white/[0.03] dark:border-ink-line">
                <div class="flex gap-1.5 items-center">
                    <span class="w-2.5 h-2.5 rounded-full bg-line dark:bg-white/10"></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-line dark:bg-white/10"></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-line dark:bg-white/10"></span>
                    <span id="preview-url" class="ml-3 font-mono text-xs text-muted">localhost/?dashboard=redis</span>
                </div>

                <div class="flex gap-1 p-1 rounded-full bg-fill dark:bg-white/5">
                    <?php
                    foreach ($previews as $slug => $label) {
                        $is_active = $slug === 'redis' ? ' active' : '';
                        $base = 'tab-link cursor-pointer py-1 px-3 text-xs font-semibold rounded-full transition-colors text-muted hover:text-ink dark:hover:text-white [&.active]:bg-white [&.active]:text-ink [&.active]:shadow-btn dark:[&.active]:bg-ink-soft dark:[&.active]:text-white';
                        echo '<button type="button" data-group="preview" data-target="preview-'.$slug.'" data-url="localhost/?dashboard='.$slug.'" class="'.$base.$is_active.'">'.$label.'</button>';
                    }
                    ?>
                </div>
            </div>

            <?php foreach ($previews as $slug => $label) { ?>
                <div id="preview-<?php echo $slug; ?>" class="<?php echo $slug === 'redis' ? '' : 'hidden '; ?>tab-content">
                    <img loading="lazy" class="w-full dark:hidden" src="<?php echo asset('assets/img/preview/'.$slug.'-light.webp'); ?>" alt="phpCacheAdmin <?php echo $label; ?> dashboard preview - light mode">
                    <img loading="lazy" class="hidden w-full dark:block" src="<?php echo asset('assets/img/preview/'.$slug.'-dark.webp'); ?>" alt="phpCacheAdmin <?php echo $label; ?> dashboard preview - dark mode">
                </div>
            <?php } ?>
        </div>
    </section>

    <section class="px-4 pt-10 pb-10 mx-auto max-w-7xl" id="benefits">
        <div class="grid grid-cols-1 gap-8 md:grid-cols-3 mt-10">
            <div class="p-7 bg-white rounded-card border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
                <h3 class="mb-3 text-lg font-semibold dark:text-white text-ink">All-in-One Solution</h3>
                <p class="text-body dark:text-gray-400 leading-relaxed">Why maintain separate installations of phpRedisAdmin, opcache-gui, and Memcached dashboards? phpCacheAdmin brings everything under one roof, saving you configuration time and server resources.</p>
            </div>
            <div class="p-7 bg-white rounded-card border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
                <h3 class="mb-3 text-lg font-semibold dark:text-white text-ink">Built for Modern Stacks</h3>
                <p class="text-body dark:text-gray-400 leading-relaxed">Fully compatible with PHP 8.2+ environments. Choose the installation method that fits your needs—run it as a standalone application, deploy it instantly via Docker, or integrate it using Composer.</p>
            </div>
            <div class="p-7 bg-white rounded-card border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
                <h3 class="mb-3 text-lg font-semibold dark:text-white text-ink">Enterprise Ready</h3>
                <p class="text-body dark:text-gray-400 leading-relaxed">Designed to handle complex setups including Redis Clusters and Access Control Lists (ACL). Securely monitor multiple remote servers directly from your centralized command center.</p>
            </div>
        </div>
    </section>

    <div class="bg-surface border-y border-line-soft dark:bg-white/[0.02] dark:border-ink-line">
        <section class="px-4 py-16 mx-auto max-w-7xl md:py-24" id="features">
            <div class="mb-8 text-center">
                <h2 class="mb-4 text-3xl font-semibold tracking-tight sm:text-4xl">Supported Cache Systems</h2>
                <p class="mx-auto max-w-2xl text-xl text-muted-foreground">
                    Explore the deep integration capabilities for each caching backend.
                </p>
            </div>

            <div class="overflow-x-auto px-4 -mx-4">
                <div class="flex justify-center w-max min-w-full">
                    <div class="inline-flex gap-1 p-1 rounded-full bg-fill dark:bg-white/5">
                        <?php
                        $links = ['Redis', 'Memcached', 'PHP Caches'];

                        $color_map = [
                            'redis'      => '[&.active]:text-redis',
                            'memcached'  => '[&.active]:text-memcached',
                            'php-caches' => '[&.active]:text-indigo-600 dark:[&.active]:text-indigo-400',
                        ];

                        foreach ($links as $index => $link) {
                            $slug = strtolower(str_replace(' ', '-', $link));
                            $is_active = $index === 0 ? ' active' : '';
                            $base = 'tab-link cursor-pointer inline-flex items-center px-5 py-2 whitespace-nowrap rounded-full font-semibold text-sm transition-colors text-body hover:text-ink dark:text-gray-400 dark:hover:text-white [&.active]:bg-white [&.active]:shadow-btn dark:[&.active]:bg-ink-soft';
                            $active_color = $color_map[$slug] ?? '[&.active]:text-ink';
                            echo '<button type="button" data-group="features" data-target="'.$slug.'" class="'.$base.' '.$active_color.$is_active.'">'.$link.'</button>';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <div id="redis" class="mt-8 tab-content">
                <div class="p-8 bg-white rounded-panel border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
                    <div class="flex gap-4 items-center pb-6 mb-6 border-b border-line-soft dark:border-ink-line">
                        <div class="flex justify-center items-center w-14 h-14 rounded-xl bg-redis/10 text-redis shrink-0">
                            <?php echo svg('redis', 32); ?>
                        </div>
                        <div>
                            <h3 class="text-2xl font-semibold tracking-tight leading-none dark:text-white text-ink">Redis Dashboard</h3>
                            <p class="mt-1 text-sm font-medium text-muted dark:text-slate-400">Minimum version 4.0 &middot; Phpredis extension or Predis (bundled)</p>
                        </div>
                    </div>

                    <ul class="grid grid-cols-1 gap-y-3 gap-x-4 md:grid-cols-2">
                        <?php
                        $features = [
                            'Comprehensive server telemetry and health monitoring',
                            'Deep metrics tracking: Fragmentation, Memory, Hit/Miss ratio',
                            'Live metrics that keep refreshing while the dashboard is open',
                            'Health checks for memory, hit rate, evictions, clients, persistence and replication',
                            'Advanced key management with native CRUD operations',
                            'Search inside a key &mdash; hash fields, set, list and sorted set members',
                            'Per-field hash TTLs (Redis 7.4) shown and editable in the key view',
                            'Built-in command console to run Redis commands right from the browser',
                            'Seamless data import and export functionality',
                            'Supports every data type, including JSON and Vector Sets (Redis 8)',
                            'Stream consumer groups with consumers, pending entries and lag',
                            'Live command profiler powered by MONITOR',
                            'Keyspace analysis with the key size distribution tracked by Redis 8',
                            'Latency monitor events, per-command percentiles and LATENCY / MEMORY DOCTOR advice',
                            'Interactive Slowlog inspector for performance debugging',
                            'Connected clients with idle time, memory and last command &mdash; disconnect any of them',
                            'Real-time Pub/Sub channel monitoring and message publishing',
                            'Native Redis Cluster and Sentinel support',
                            'Secure connections via ACL (Access Control List)',
                            'Works with Valkey and KeyDB',
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

                    <p class="pt-5 mt-7 text-sm border-t border-line-soft text-muted dark:border-ink-line dark:text-gray-400">
                        Coming from another Redis GUI? See the full
                        <a href="phpredisadmin-alternative.php" class="font-medium text-blue-700 dark:text-blue-400 hover:underline">phpRedisAdmin comparison</a> or the
                        <a href="redis-commander-alternative.php" class="font-medium text-blue-700 dark:text-blue-400 hover:underline">Redis Commander comparison</a>.
                    </p>
                </div>
            </div>

            <div id="memcached" class="hidden mt-8 tab-content">
                <div class="p-8 bg-white rounded-panel border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
                    <div class="flex gap-4 items-center pb-6 mb-6 border-b border-line-soft dark:border-ink-line">
                        <div class="flex justify-center items-center w-14 h-14 rounded-xl bg-memcached/10 text-memcached shrink-0">
                            <?php echo svg('memcached', 32); ?>
                        </div>
                        <div>
                            <h3 class="text-2xl font-semibold tracking-tight leading-none dark:text-white text-ink">Memcached Manager</h3>
                            <p class="mt-1 text-sm font-medium text-muted dark:text-slate-400">Minimum version 1.4.31 &middot; No PHP extension required (Uses a custom client)</p>
                        </div>
                    </div>

                    <ul class="grid grid-cols-1 gap-y-3 gap-x-4 md:grid-cols-2">
                        <?php
                        $features = [
                            'Live monitoring of server health and uptime',
                            'Visualized analytics for cache hit rates and memory allocation',
                            'Live metrics that keep refreshing while the dashboard is open',
                            'Direct data manipulation (Create, Read, Update, Delete)',
                            'Built-in command console to run Memcached commands right from the browser',
                            'Reliable key export and import features',
                            'Keyspace analysis with item size distribution (servers started with track_sizes)',
                            'Watcher streaming reads, writes, evictions and deletions as they happen',
                            'Open connections with their state and idle time',
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

                    <p class="pt-5 mt-7 text-sm border-t border-line-soft text-muted dark:border-ink-line dark:text-gray-400">
                        Switching from phpMemcachedAdmin? See the full
                        <a href="phpmemcachedadmin-alternative.php" class="font-medium text-blue-700 dark:text-blue-400 hover:underline">phpMemcachedAdmin vs phpCacheAdmin comparison</a>.
                    </p>
                </div>
            </div>

            <div id="php-caches" class="hidden mt-8 tab-content">
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                    <div class="p-7 bg-white rounded-card border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
                        <div class="flex gap-3.5 items-center pb-5 mb-5 border-b border-line-soft dark:border-ink-line">
                            <div class="flex justify-center items-center w-12 h-12 rounded-xl bg-opcache/10 text-opcache shrink-0">
                                <?php echo svg('opcache', 24); ?>
                            </div>
                            <div>
                                <h3 class="text-xl font-semibold leading-none dark:text-white text-ink">OPCache</h3>
                                <p class="mt-1 text-xs font-medium text-muted dark:text-slate-400">Requires OPcache extension</p>
                            </div>
                        </div>

                        <ul class="grid grid-cols-1 gap-y-3">
                            <?php
                            $features = [
                                'Live RAM consumption graphs',
                                'Interactive memory map of cached scripts, sized by RAM usage',
                                'Examine individually compiled PHP scripts',
                                'Force-invalidate specific files on demand',
                                'Preloaded files kept in memory for the life of the server',
                                'Directory warmup right after a deployment',
                                'Compiler optimization suggestions',
                                'Visual hit/miss execution metrics',
                                'Automatic health checks',
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

                    <div class="p-7 bg-white rounded-card border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
                        <div class="flex gap-3.5 items-center pb-5 mb-5 border-b border-line-soft dark:border-ink-line">
                            <div class="flex justify-center items-center w-12 h-12 rounded-xl bg-apcu/10 text-apcu shrink-0">
                                <?php echo svg('apcu', 24); ?>
                            </div>
                            <div>
                                <h3 class="text-xl font-semibold leading-none dark:text-white text-ink">APCu</h3>
                                <p class="mt-1 text-xs font-medium text-muted dark:text-slate-400">Requires APCu extension</p>
                            </div>
                        </div>

                        <ul class="grid grid-cols-1 gap-y-3">
                            <?php
                            $features = [
                                'Complete shared memory overview',
                                'Success rate charting',
                                'Inspect and edit user-cached variables',
                                'Cache analysis and health checks',
                                'Memory map of every shared segment, block by block',
                                'Namespace and expiry breakdown of cached entries',
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

                    <div class="p-7 bg-white rounded-card border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
                        <div class="flex gap-3.5 items-center pb-5 mb-5 border-b border-line-soft dark:border-ink-line">
                            <div class="flex justify-center items-center w-12 h-12 rounded-xl bg-realpath/10 text-realpath shrink-0">
                                <?php echo svg('realpath', 24); ?>
                            </div>
                            <div>
                                <h3 class="text-xl font-semibold leading-none dark:text-white text-ink">Realpath</h3>
                                <p class="mt-1 text-xs font-medium text-muted dark:text-slate-400">PHP Stat Cache Monitor</p>
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
                                'Health checks for cache size, expired entries and TTL',
                                'Warns when open_basedir turns the cache off entirely',
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

                <p class="mt-8 text-sm text-center text-body dark:text-gray-400">
                    Every install also includes a
                    <strong class="font-semibold text-ink dark:text-white">Server</strong> dashboard &mdash; PHP version and configuration, loaded extensions,
                    <code>phpinfo()</code>, plus CPU, RAM and disk usage of the machine phpCacheAdmin runs on.
                </p>

                <p class="mt-3 text-sm text-center text-muted dark:text-gray-400">
                    Using a standalone OPcache script? See the full
                    <a href="opcache-gui-alternative.php" class="font-medium text-blue-700 dark:text-blue-400 hover:underline">opcache-gui vs phpCacheAdmin comparison</a>.
                </p>
            </div>
        </section>
    </div>

    <section id="installation" class="py-20 px-4 mx-auto max-w-4xl">
        <div class="mb-10 text-center">
            <h2 class="mb-4 text-3xl font-semibold tracking-tight sm:text-4xl dark:text-white text-ink">Get Started in Seconds</h2>
            <p class="text-lg text-body dark:text-gray-400">
                Choose the installation method that fits your workflow. No complicated dependencies are required.
            </p>
        </div>

        <div class="overflow-hidden bg-white rounded-panel border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
            <div class="border-b border-line bg-surface dark:border-ink-line dark:bg-white/5">
                <div class="flex">
                    <button type="button" data-group="install" data-target="manual" class="tab-link active flex-1 py-4 text-sm font-semibold text-center border-b-2 border-transparent transition-colors cursor-pointer text-muted hover:text-ink dark:text-gray-400 dark:hover:text-gray-200 [&.active]:text-ink [&.active]:border-blue-700 dark:[&.active]:text-white">
                        Manual Download
                    </button>
                    <button type="button" data-group="install" data-target="docker" class="tab-link flex-1 py-4 text-sm font-semibold text-center border-b-2 border-transparent transition-colors cursor-pointer text-muted hover:text-ink dark:text-gray-400 dark:hover:text-gray-200 [&.active]:text-ink [&.active]:border-blue-700 dark:[&.active]:text-white">
                        Docker Image
                    </button>
                    <button type="button" data-group="install" data-target="composer" class="tab-link flex-1 py-4 text-sm font-semibold text-center border-b-2 border-transparent transition-colors cursor-pointer text-muted hover:text-ink dark:text-gray-400 dark:hover:text-gray-200 [&.active]:text-ink [&.active]:border-blue-700 dark:[&.active]:text-white">
                        Composer Require
                    </button>
                </div>
            </div>

            <div class="p-6 sm:p-10">
                <div id="manual" class="space-y-6 tab-content">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div class="p-6 bg-surface rounded-box border border-line-soft dark:bg-white/5 dark:border-ink-line">
                            <div class="mb-3 font-mono text-xs font-medium tracking-widest text-gray-400 select-none dark:text-gray-500">STEP 1</div>
                            <h3 class="mb-2 text-base font-semibold dark:text-white text-ink">Download</h3>
                            <p class="mb-5 text-sm text-body dark:text-gray-400">Get the latest release zip file directly from GitHub.</p>
                            <a href="https://github.com/RobiNN1/phpCacheAdmin/releases" target="_blank" rel="noopener noreferrer" class="inline-flex items-center py-2.5 px-4.5 text-sm font-semibold text-white bg-blue-700 rounded-btn shadow-btn transition-colors hover:bg-blue-800 dark:hover:bg-blue-600">
                                Go to Releases
                            </a>
                        </div>

                        <div class="p-6 bg-surface rounded-box border border-line-soft dark:bg-white/5 dark:border-ink-line">
                            <div class="mb-3 font-mono text-xs font-medium tracking-widest text-gray-400 select-none dark:text-gray-500">STEP 2</div>
                            <h3 class="mb-2 text-base font-semibold dark:text-white text-ink">Unzip & Config</h3>
                            <p class="text-sm text-body dark:text-gray-400">
                                Unzip the folder to your web directory. Optionally copy
                                <code class="py-0.5 px-1.5 text-xs bg-fill rounded-[5px] dark:bg-white/10">config.dist.php</code> to
                                <code class="py-0.5 px-1.5 text-xs bg-fill rounded-[5px] dark:bg-white/10">config.php</code>, or configure it with environment variables or a
                                <code class="py-0.5 px-1.5 text-xs bg-fill rounded-[5px] dark:bg-white/10">.env</code> file.
                            </p>
                        </div>
                    </div>

                    <p class="text-sm text-muted dark:text-gray-400">
                        <strong class="font-semibold text-gray-700 dark:text-gray-300">Updating?</strong>
                        Just replace the files &mdash; the template cache is cleared automatically when a new version is detected.
                    </p>
                </div>

                <div id="docker" class="hidden space-y-6 tab-content">
                    <div>
                        <h3 class="mb-2 text-base font-semibold dark:text-white text-ink">Run with a single command</h3>
                        <p class="mb-4 text-sm text-body dark:text-gray-400">The fastest way to get started. Pulls the lightweight image and exposes the interface on port 8080.</p>

                        <?php
                        echo command_block([
                            '<span class="text-purple-300">docker</span> run -p <span class="text-emerald-300">8080:80</span> -d --name phpcacheadmin',
                            '-e <span class="text-yellow-300">"PCA_REDIS_0_HOST=redis_host"</span>',
                            '-e <span class="text-yellow-300">"PCA_REDIS_0_PORT=6379"</span>',
                            '-e <span class="text-yellow-300">"PCA_MEMCACHED_0_HOST=memcached_host"</span>',
                            '-e <span class="text-yellow-300">"PCA_MEMCACHED_0_PORT=11211"</span>',
                            'robinn/phpcacheadmin',
                        ], 'docker run -p 8080:80 -d --name phpcacheadmin -e "PCA_REDIS_0_HOST=redis_host" -e "PCA_REDIS_0_PORT=6379" -e "PCA_MEMCACHED_0_HOST=memcached_host" -e "PCA_MEMCACHED_0_PORT=11211" robinn/phpcacheadmin');
                        ?>

                        <div class="mt-4 text-sm text-muted dark:text-gray-400">
                            Need more configuration? Check out the
                            <a href="https://github.com/RobiNN1/phpCacheAdmin?tab=readme-ov-file#environment-variables" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-700 dark:text-blue-400 hover:underline">
                                Environment Variables documentation
                            </a>
                        </div>
                    </div>
                </div>

                <div id="composer" class="hidden space-y-8 tab-content">
                    <div>
                        <h3 class="mb-3 font-semibold dark:text-white text-ink">Install via Composer</h3>
                        <p class="mb-5 text-sm text-body dark:text-gray-400">Seamlessly integrate the dashboard into your existing PHP application or framework.</p>

                        <?php
                        echo command_block([
                            '<span class="text-purple-300">composer</span> require robinn/phpcacheadmin',
                        ], 'composer require robinn/phpcacheadmin');
                        ?>
                    </div>

                    <div>
                        <h3 class="mb-3 font-semibold dark:text-white text-ink">Embed in your application</h3>
                        <div class="overflow-x-auto p-6 rounded-box bg-ink">
                            <code class="font-mono text-sm leading-relaxed text-slate-200">
                                <span class="text-muted">// Copy config.dist.php from the vendor folder and set 'pcapath' &amp; 'url'.</span><br>
                                <span class="text-amber-200">\RobiNN\Pca\Config</span>::setConfigPath(__DIR__.<span class="text-emerald-300">'/phpcacheadmin.config.php'</span>);<br>
                                <br>
                                <span class="text-muted">// Or configure it with a .env file (requires vlucas/phpdotenv).</span><br>
                                <span class="text-amber-200">\RobiNN\Pca\Config</span>::loadDotenv(__DIR__);<br>
                                <br>
                                <span class="text-muted">// Optional: built-in auth, or secure it behind your own route.</span><br>
                                <span class="text-amber-200">\RobiNN\Pca\Auth</span>::check();<br>
                                <br>
                                <span class="text-purple-300">echo</span> (<span class="text-sky-300">new</span>
                                <span class="text-amber-200">\RobiNN\Pca\Admin</span>())-&gt;render();
                            </code>
                        </div>
                        <p class="mt-4 text-sm text-body dark:text-gray-400">
                            Set
                            <code class="py-0.5 px-1.5 text-xs bg-fill rounded-[5px] dark:bg-white/10">pcapath</code> (URL the assets are served from) and
                            <code class="py-0.5 px-1.5 text-xs bg-fill rounded-[5px] dark:bg-white/10">url</code> (where the dashboard is mounted) in your config. See the
                            <a href="https://github.com/RobiNN1/phpCacheAdmin/blob/master/example_embedded_version.php" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-700 dark:text-blue-400 hover:underline">embedded example</a>.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="bg-surface border-y border-line-soft dark:bg-white/[0.02] dark:border-ink-line">
        <section class="px-4 py-16 mx-auto max-w-4xl md:py-24" id="faq">
            <div class="mb-10 text-center">
                <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl dark:text-white text-ink">Frequently Asked Questions</h2>
            </div>
            <?php
            $faqs = [
                [
                    'Is phpCacheAdmin an alternative to phpRedisAdmin and phpMemcachedAdmin?',
                    'Yes. It is a modern, actively maintained alternative that replaces both tools. It resolves common issues found in older scripts, such as memory exhaustion crashes, by using the SCAN command instead of KEYS. It also introduces support for Redis Clusters, ACL security, and seamless Docker integration. See how it compares as a
                    <a href="phpredisadmin-alternative.php" class="font-medium text-blue-700 dark:text-blue-400 hover:underline">phpRedisAdmin alternative</a> or
                    <a href="phpmemcachedadmin-alternative.php" class="font-medium text-blue-700 dark:text-blue-400 hover:underline">phpMemcachedAdmin alternative</a>.',
                ],
                [
                    'Is there an alternative to Redis Commander that does not need Node.js?',
                    'Yes. Redis Commander runs as a separate Node.js service, while phpCacheAdmin runs on the PHP 8.2+ stack you already have &mdash; or from the Docker image. Both handle Redis Cluster, Sentinel, ACL, SCAN, a tree view, an interactive console and a read-only mode; phpCacheAdmin adds a MONITOR profiler, Pub/Sub, Slowlog, latency insights, keyspace analysis, historical metrics and dashboards for Memcached, OPCache and APCu. See the full
                    <a href="redis-commander-alternative.php" class="font-medium text-blue-700 dark:text-blue-400 hover:underline">Redis Commander alternative</a> comparison.',
                ],
                [
                    'Can I use this to replace opcache-gui?',
                    'Absolutely. Instead of hosting a standalone script just for OPCache, phpCacheAdmin provides a comprehensive view of OPCache, APCu, and Realpath cache right alongside your Redis and Memcached instances, all within a single unified dashboard. See the full
                    <a href="opcache-gui-alternative.php" class="font-medium text-blue-700 dark:text-blue-400 hover:underline">opcache-gui alternative</a> comparison.',
                ],
                [
                    'Does phpCacheAdmin require a database?',
                    'No external database like MySQL is needed. It communicates directly with your caching servers. Historical metrics are saved locally using PHP\'s native SQLite3 extension.',
                ],
                [
                    'Can I manage multiple servers simultaneously?',
                    'Yes, you can configure an unlimited number of Redis and Memcached instances in your configuration file and switch between them directly from the navigation bar.',
                ],
                [
                    'Is it safe to use in a production environment?',
                    'Yes, but a proper setup is required. It ships with built-in authentication &mdash; define one or more users in the
                    <code>authusers</code> option (username =&gt; password) in your config to enable a login screen. Alternatively, secure the dashboard behind your own security layer (such as a reverse proxy or an authenticated route). Additionally, it supports Redis ACL, a read-only mode and configurable SCAN limits to prevent blocking the main Redis thread on large databases.',
                ],
                [
                    'How do I fix "Fatal error: Allowed memory size exhausted"?',
                    'This typically happens when you have millions of keys in Redis and limited PHP RAM, because the tool uses the
                    <code>KEYS</code> command by default. To resolve this, open your configuration file and enable the
                    <code>SCAN</code> command (e.g., set <code>PCA_REDIS_0_SCANSIZE</code> or uncomment
                    <code>scansize</code> in <code>config.php</code>).',
                ],
                [
                    'Can I collect metrics in the background?',
                    'Yes, you can collect historical data even when the dashboard is not open in your browser by setting up a cronjob. Trigger the metrics endpoint for your desired cache periodically, for example:
                    <code class="py-0.5 px-1.5 text-xs bg-fill rounded-[5px] dark:bg-white/10">curl -s "https://example.com/?dashboard=redis&amp;server=0&amp;ajax&amp;metrics" &gt; /dev/null</code>.
                    If authentication is enabled, set the <code>authtoken</code> option in your config and append
                    <code class="py-0.5 px-1.5 text-xs bg-fill rounded-[5px] dark:bg-white/10">&amp;token=your-secret-token</code> to the URL so the cronjob can run without a login session.',
                ],
            ];

            echo ld_json(faq_schema($faqs));
            ?>
            <div class="space-y-6">
                <?php foreach ($faqs as [$question, $answer]) { ?>
                    <div class="p-6 bg-white rounded-box border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
                        <h3 class="text-base font-semibold dark:text-white text-ink"><?php echo $question; ?></h3>
                        <p class="mt-2 text-body dark:text-gray-400 text-sm"><?php echo $answer; ?></p>
                    </div>
                <?php } ?>
            </div>
        </section>
    </div>
<?php
require_once __DIR__.'/_footer.php';
