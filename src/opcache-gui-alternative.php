<?php
declare(strict_types=1);

$page_title = 'opcache-gui Alternative - phpCacheAdmin | PHP OPcache GUI';
$page_desc = 'Compare opcache-gui vs phpCacheAdmin: a modern OPcache GUI with a treemap memory map, deployment warmup and health checks, plus APCu, Realpath, Redis & Memcached.';
$canonical_url = 'https://phpcacheadmin.com/opcache-gui-alternative';
$page_keywords = 'opcache-gui alternative, opcache-gui vs phpCacheAdmin, amnuts opcache-gui, OPCache GUI, PHP OPCache GUI, Zend OPcache GUI, OPcache warmup, OPcache treemap, PHP OPCache dashboard, OPCache monitor, phpCacheAdmin, clear opcache GUI';

require __DIR__.'/_header.php';

$comparison = [
    ['OPcache statistics & hit rates', true, true],
    ['Invalidate cached scripts', true, true],
    ['Preloaded files overview', true, true],
    ['Treemap memory map of cached scripts', true, false],
    ['Directory warmup after deployment', true, false],
    ['Health checks', true, false],
    ['Historical metrics & charts', 'SQLite-backed history', 'Live polling only'],
    ['APCu user cache editor', true, false],
    ['Realpath cache viewer', true, false],
    ['Redis & Memcached management', true, false],
    ['Server dashboard (phpinfo, CPU, RAM, disk)', true, false],
    ['Built-in login & read-only mode', true, false],
    ['Dark mode', true, false],
    ['Single-file drop-in', 'Full app (zip, Docker, Composer)', 'Yes, one file'],
    ['License', 'MIT', 'MIT'],
];

$faqs = [
    [
        'Is phpCacheAdmin a full replacement for opcache-gui?',
        'Yes. Everything opcache-gui shows is in the OPcache dashboard: statistics, memory usage, hit rates, cached scripts with per-file invalidation and preloaded files. On top of that you get a treemap visualization of cached scripts, a directory warmup, health checks and historical charts.',
    ],
    [
        'Can I monitor my application\'s OPcache from the Docker image?',
        'OPcache lives inside each PHP process, so any OPcache GUI has to run on the same PHP runtime as your application - that applies to opcache-gui and phpCacheAdmin alike. Use the release zip or the Composer package inside your app\'s server for OPcache, and the Docker image for Redis and Memcached.',
    ],
    [
        'What is OPcache warmup and does phpCacheAdmin support it?',
        'After every deployment OPcache starts empty and the first visitors pay the compilation cost. phpCacheAdmin\'s warmup compiles a whole directory into the cache on demand, so the cache is hot before real traffic arrives. opcache-gui does not offer cache preheating.',
    ],
    [
        'Does phpCacheAdmin also cover APCu and the realpath cache?',
        'Yes. Besides OPcache it includes an APCu dashboard where you can view and edit user-cached entries, a Realpath cache viewer, and full management dashboards for Redis and Memcached - all in one interface.',
    ],
    [
        'Is phpCacheAdmin free for commercial use?',
        'Yes. Both tools are MIT-licensed open source. phpCacheAdmin can be used freely in personal and commercial projects.',
    ],
];

echo ld_json(faq_schema($faqs));
echo ld_json(breadcrumb_schema('opcache-gui Alternative', $canonical_url));
?>

    <section class="px-4 pt-20 pb-10 mx-auto max-w-7xl md:pt-32">
        <div class="mb-16 text-center">
            <h1 class="mb-6 text-4xl font-semibold tracking-tight leading-[1.05] sm:text-5xl lg:text-6xl text-balance">
                The Best
                <span class="text-opcache">opcache-gui</span> Alternative
            </h1>
            <p class="mx-auto mb-8 max-w-4xl text-lg leading-relaxed text-body sm:mb-10 sm:text-xl dark:text-gray-400 text-balance">
                While amnuts/opcache-gui is a great single-purpose script, modern web applications often rely on multiple caching layers. phpCacheAdmin is a modern OPCache GUI with a treemap memory map, deployment warmup and health checks &mdash; and it manages Redis, Memcached, APCu and Realpath cache too.
            </p>
            <div class="flex flex-wrap gap-4 justify-center">
                <a href="#comparison" class="inline-flex gap-2 items-center py-3.5 px-6 text-base font-semibold text-white bg-blue-700 rounded-btn shadow-btn transition-colors hover:bg-blue-800 dark:bg-blue-700 dark:hover:bg-blue-600">
                    See the Comparison
                </a>
                <a href="/" class="inline-flex gap-2.5 items-center py-3.5 px-6 text-base font-semibold text-ink bg-white rounded-btn border border-line shadow-btn transition-colors hover:bg-surface hover:border-gray-300 dark:text-white dark:bg-white/5 dark:border-white/10 dark:hover:bg-white/10">
                    Discover phpCacheAdmin
                </a>
            </div>
        </div>

        <div class="overflow-hidden mt-16 mx-auto max-w-5xl rounded-card border border-line shadow-lift dark:border-ink-line dark:shadow-none">
            <div class="flex gap-1.5 items-center py-3 px-4 border-b bg-surface border-line-soft dark:bg-white/3 dark:border-ink-line">
                <span class="w-2.5 h-2.5 rounded-full bg-line dark:bg-white/10"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-line dark:bg-white/10"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-line dark:bg-white/10"></span>
                <span class="ml-3 font-mono text-xs text-muted">localhost/?dashboard=opcache</span>
            </div>
            <img loading="lazy" class="w-full dark:hidden" src="<?php echo asset('assets/img/preview/opcache-light.webp'); ?>" alt="phpCacheAdmin OPcache dashboard preview - light mode">
            <img loading="lazy" class="hidden w-full dark:block" src="<?php echo asset('assets/img/preview/opcache-dark.webp'); ?>" alt="phpCacheAdmin OPcache dashboard preview - dark mode">
        </div>
    </section>

    <div class="bg-surface border-y border-line-soft dark:bg-white/2 dark:border-ink-line">
        <section class="px-4 py-16 mx-auto max-w-5xl md:py-20" id="comparison">
            <div class="mb-10 text-center">
                <h2 class="mb-4 text-3xl font-semibold tracking-tight sm:text-4xl dark:text-white text-ink">opcache-gui vs. phpCacheAdmin</h2>
                <p class="mx-auto max-w-2xl text-lg text-body dark:text-gray-400">
                    A side-by-side look at what you get when you switch.
                </p>
            </div>

            <div class="overflow-hidden bg-white rounded-panel border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                        <tr class="border-b border-line bg-surface dark:border-ink-line dark:bg-white/5">
                            <th class="py-4 px-6 font-semibold text-left text-ink dark:text-white">Feature</th>
                            <th class="py-4 px-6 font-semibold text-center text-blue-700 dark:text-blue-400">phpCacheAdmin</th>
                            <th class="py-4 px-6 font-semibold text-center text-muted dark:text-gray-400">opcache-gui</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($comparison as [$feature, $pca, $other]) { ?>
                            <tr class="border-b border-line last:border-b-0 dark:border-ink-line">
                                <td class="py-3.5 px-6 font-medium text-slate-700 dark:text-slate-300"><?php echo $feature; ?></td>
                                <td class="py-3.5 px-6 text-center"><?php echo compare_cell($pca, true); ?></td>
                                <td class="py-3.5 px-6 text-center"><?php echo compare_cell($other, false); ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <section class="px-4 py-16 mx-auto max-w-5xl md:py-20">
        <div class="p-8 bg-white rounded-panel border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
            <h2 class="mb-8 text-2xl font-semibold tracking-tight dark:text-white text-ink">Why choose phpCacheAdmin over opcache-gui?</h2>

            <div class="space-y-8">
                <div>
                    <h3 class="text-lg font-semibold text-opcache mb-2">1. All-in-One Dashboard</h3>
                    <p class="text-body dark:text-gray-400">Stop jumping between different scripts for different caches. Monitor your PHP Zend OPcache, inspect Redis keys, and check Memcached hit rates all from a single, centralized command center.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-opcache mb-2">2. Cached Scripts Memory Map</h3>
                    <p class="text-body dark:text-gray-400">An interactive treemap visualizes every cached PHP script sized by its memory consumption. Click a folder to zoom in and instantly see which parts of your codebase use the most OPcache memory.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-opcache mb-2">3. Deployment Warmup</h3>
                    <p class="text-body dark:text-gray-400">After a deployment OPcache starts cold, and your first visitors pay the compilation cost. The warmup feature compiles a whole directory into the cache on demand, so the cache is hot before real traffic hits it.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-opcache mb-2">4. Health Checks & Server Overview</h3>
                    <p class="text-body dark:text-gray-400">Automatic health checks keep an eye on your OPcache configuration and usage. The Server dashboard adds a quick look at the machine itself: PHP version and configuration, loaded extensions,
                        <code>phpinfo()</code>, and CPU, RAM and disk usage.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-opcache mb-2">5. Realpath & APCu Support</h3>
                    <p class="text-body dark:text-gray-400">Get deeper insights into your PHP environment. Beyond just OPCache scripts, you can monitor the Realpath stat cache and explore APCu user cache allocations &mdash; including editing and deleting cached entries.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-opcache mb-2">6. Modern, Responsive UI</h3>
                    <p class="text-body dark:text-gray-400">phpCacheAdmin features a clean, Tailwind-powered interface that looks great on any device, with a native dark mode. An optional login screen and read-only mode make it safe to share with the whole team.</p>
                </div>
            </div>
        </div>
    </section>

    <div class="bg-surface border-y border-line-soft dark:bg-white/2 dark:border-ink-line">
        <section class="px-4 py-16 mx-auto max-w-4xl md:py-20" id="faq">
            <div class="mb-10 text-center">
                <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl dark:text-white text-ink">Frequently Asked Questions</h2>
            </div>
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

    <section class="px-4 py-20 mx-auto max-w-4xl text-center">
        <h2 class="mb-4 text-3xl font-semibold tracking-tight sm:text-4xl dark:text-white text-ink">Ready to try phpCacheAdmin?</h2>
        <p class="mx-auto mb-8 max-w-2xl text-lg text-body dark:text-gray-400">Free, open source and running in under a minute.</p>
        <div class="flex flex-wrap gap-4 justify-center">
            <a href="/#installation" class="inline-flex gap-2 items-center py-3.5 px-6 text-base font-semibold text-white bg-blue-700 rounded-btn shadow-btn transition-colors hover:bg-blue-800 dark:bg-blue-700 dark:hover:bg-blue-600">
                Get phpCacheAdmin
            </a>
            <a href="https://github.com/RobiNN1/phpCacheAdmin" target="_blank" rel="noopener noreferrer" class="inline-flex gap-2.5 items-center py-3.5 px-6 text-base font-semibold text-ink bg-white rounded-btn border border-line shadow-btn transition-colors hover:bg-surface hover:border-gray-300 dark:text-white dark:bg-white/5 dark:border-white/10 dark:hover:bg-white/10">
                <?php echo svg('github', 20); ?>
                <span>Star on GitHub</span>
            </a>
        </div>
    </section>

<?php
require __DIR__.'/_footer.php';
