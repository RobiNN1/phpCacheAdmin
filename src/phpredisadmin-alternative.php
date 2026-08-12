<?php
declare(strict_types=1);

$page_title = 'phpRedisAdmin Alternative - phpCacheAdmin | PHP Redis Admin GUI';
$page_desc = 'Compare phpRedisAdmin vs phpCacheAdmin and switch in minutes. A modern, actively maintained PHP Redis admin GUI with Docker, Redis Cluster, ACL & SCAN support.';
$canonical_url = 'https://phpcacheadmin.com/phpredisadmin-alternative';
$page_keywords = 'phpRedisAdmin alternative, phpRedisAdmin vs phpCacheAdmin, PHP Redis admin, Redis GUI, phpCacheAdmin, replace phpRedisAdmin, migrate from phpRedisAdmin, Redis Cluster GUI, Docker Redis dashboard';

require __DIR__.'/_header.php';

$comparison = [
    ['Modern PHP support', 'Built for PHP 8.2+', 'Aging codebase'],
    ['Redis Cluster', true, false],
    ['Redis Sentinel', true, false],
    ['Redis ACL (username + password)', true, 'Password (AUTH) only'],
    ['Large databases', 'SCAN engine, millions of keys', 'KEYS-based, can exhaust memory'],
    ['Streams & consumer groups', true, false],
    ['Vector Sets (Redis 8)', true, false],
    ['JSON (RedisJSON)', true, false],
    ['Live command profiler (MONITOR)', true, false],
    ['Keyspace analysis', true, false],
    ['Slowlog inspector', true, false],
    ['Latency monitor & LATENCY / MEMORY DOCTOR', true, false],
    ['Connected clients (list & disconnect)', true, false],
    ['Per-field hash TTLs (Redis 7.4)', true, false],
    ['Real-time Pub/Sub monitoring', true, false],
    ['Built-in command console', true, false],
    ['Formatted, raw & hex value view', true, false],
    ['Historical metrics & health checks', true, false],
    ['Read-only mode', true, false],
    ['Memcached, OPCache, APCu & Realpath', 'Same dashboard', 'Redis only'],
    ['Dark mode', true, false],
    ['Docker image', true, true],
    ['Composer install', true, true],
    ['License', 'MIT', 'CC BY 3.0'],
];

$faqs = [
    [
        'Is phpRedisAdmin still maintained?',
        'phpRedisAdmin is one of the oldest Redis web interfaces and new development has largely stalled. It predates modern Redis features, so there is no support for Redis Cluster, Sentinel, ACL usernames, Streams or Vector Sets. phpCacheAdmin is actively maintained and supports all of them.',
    ],
    [
        'What is the best phpRedisAdmin alternative?',
        'phpCacheAdmin is the top-ranked phpRedisAdmin alternative on AlternativeTo. It covers everything phpRedisAdmin does - browsing, editing and deleting keys - and adds Redis Cluster, ACL, a Slowlog inspector, Pub/Sub monitoring, a command console and dashboards for Memcached, OPCache, APCu and Realpath cache.',
    ],
    [
        'Does phpCacheAdmin support Redis 8 Vector Sets and Valkey?',
        'Yes. phpCacheAdmin supports every Redis data type including Vector Sets introduced in Redis 8, plus Stream consumer groups with their consumers, pending entries and lag. It also works with Redis-compatible servers such as Valkey and KeyDB.',
    ],
    [
        'Do I need to migrate my Redis data?',
        'No. Both tools are just web interfaces on top of your Redis server; the data stays in Redis. Point phpCacheAdmin at the same host and port that phpRedisAdmin used and all your keys appear immediately.',
    ],
    [
        'Does phpCacheAdmin have a Docker image like phpRedisAdmin?',
        'Yes. Run docker run -p 8080:80 -e "PCA_REDIS_0_HOST=your_host" robinn/phpcacheadmin and open localhost:8080. The image is lightweight and configurable entirely through environment variables.',
    ],
    [
        'Is phpCacheAdmin free for commercial use?',
        'Yes. phpCacheAdmin is open source under the MIT license, so you can use it freely in personal and commercial projects. phpRedisAdmin is released under the Creative Commons Attribution 3.0 license.',
    ],
];

echo ld_json(faq_schema($faqs));
echo ld_json(breadcrumb_schema('phpRedisAdmin Alternative', $canonical_url));
?>

    <section class="px-4 pt-20 pb-10 mx-auto max-w-7xl md:pt-32">
        <div class="mb-16 text-center">
            <h1 class="mb-6 text-4xl font-semibold tracking-tight leading-[1.05] sm:text-5xl lg:text-6xl text-balance">
                The Best
                <span class="text-redis">phpRedisAdmin</span> Alternative
            </h1>
            <p class="mx-auto mb-8 max-w-4xl text-lg leading-relaxed text-body sm:mb-10 sm:text-xl dark:text-gray-400 text-balance">
                phpCacheAdmin is a modern web interface to manage Redis databases &mdash; a direct replacement for phpRedisAdmin built for today's PHP stacks. Browse, edit and delete keys just like before, plus Redis Cluster, Sentinel, ACL, Vector Sets and Docker.
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
            <div class="flex gap-1.5 items-center py-3 px-4 border-b bg-surface border-line-soft dark:bg-white/[0.03] dark:border-ink-line">
                <span class="w-2.5 h-2.5 rounded-full bg-line dark:bg-white/10"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-line dark:bg-white/10"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-line dark:bg-white/10"></span>
                <span class="ml-3 font-mono text-xs text-muted">localhost/?dashboard=redis</span>
            </div>
            <img loading="lazy" class="w-full dark:hidden" src="<?php echo asset('assets/img/preview/redis-light.webp'); ?>" alt="phpCacheAdmin Redis dashboard preview - light mode">
            <img loading="lazy" class="hidden w-full dark:block" src="<?php echo asset('assets/img/preview/redis-dark.webp'); ?>" alt="phpCacheAdmin Redis dashboard preview - dark mode">
        </div>
    </section>

    <div class="bg-surface border-y border-line-soft dark:bg-white/[0.02] dark:border-ink-line">
        <section class="px-4 py-16 mx-auto max-w-5xl md:py-20" id="comparison">
            <div class="mb-10 text-center">
                <h2 class="mb-4 text-3xl font-semibold tracking-tight sm:text-4xl dark:text-white text-ink">phpRedisAdmin vs phpCacheAdmin</h2>
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
                            <th class="py-4 px-6 font-semibold text-center text-muted dark:text-gray-400">phpRedisAdmin</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($comparison as [$feature, $pca, $pra]) { ?>
                            <tr class="border-b border-line last:border-b-0 dark:border-ink-line">
                                <td class="py-3.5 px-6 font-medium text-slate-700 dark:text-slate-300"><?php echo $feature; ?></td>
                                <td class="py-3.5 px-6 text-center"><?php echo compare_cell($pca, true); ?></td>
                                <td class="py-3.5 px-6 text-center"><?php echo compare_cell($pra, false); ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="mt-6 text-sm text-center text-muted dark:text-gray-400">
                Comparing other Redis GUIs? There is also a
                <a href="redis-commander-alternative.php" class="font-medium text-blue-700 dark:text-blue-400 hover:underline">Redis Commander vs phpCacheAdmin comparison</a>.
            </p>
        </section>
    </div>

    <section class="px-4 py-16 mx-auto max-w-5xl md:py-20">
        <div class="p-8 bg-white rounded-panel border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
            <h2 class="mb-8 text-2xl font-semibold tracking-tight dark:text-white text-ink">Why choose phpCacheAdmin over phpRedisAdmin?</h2>

            <div class="space-y-8">
                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">1. Actively Maintained & Modern Codebase</h3>
                    <p class="text-body dark:text-gray-400">phpRedisAdmin was a great tool, but it lacks support for modern Redis features and PHP versions. phpCacheAdmin is built specifically for PHP 8.2+ with zero heavy dependencies.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">2. Native Redis Cluster, Sentinel & ACL Support</h3>
                    <p class="text-body dark:text-gray-400">Enterprise setups require proper security and scaling. phpCacheAdmin natively supports Redis Clusters, Sentinel and Access Control Lists (ACL), allowing you to manage production environments securely.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">3. Redis 8 Ready: Vector Sets & Stream Groups</h3>
                    <p class="text-body dark:text-gray-400">phpCacheAdmin keeps up with Redis itself. Browse and edit every data type including Vector Sets introduced in Redis 8, and inspect Stream consumer groups with their consumers, pending entries and lag. Values can be shown formatted, raw or as a hex dump. It also works with Valkey and KeyDB.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">4. Not Just Redis</h3>
                    <p class="text-body dark:text-gray-400">Why use multiple dashboards? Manage Redis, Memcached, OPCache, and APCu from a single unified interface. Save server resources and configuration time.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">5. Performance First (SCAN vs. KEYS)</h3>
                    <p class="text-body dark:text-gray-400">phpRedisAdmin often crashes on large databases due to memory exhaustion. phpCacheAdmin allows you to seamlessly switch to the SCAN command, ensuring smooth performance even with millions of keys.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">6. Profiler, Slowlog & Keyspace Analysis</h3>
                    <p class="text-body dark:text-gray-400">Diagnose performance without touching the CLI. Watch commands live with the MONITOR-based profiler (across every cluster node at once), inspect the Slowlog directly in the dashboard, and run a keyspace analysis to understand what is actually stored in your database.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">7. Real-time Pub/Sub Monitoring</h3>
                    <p class="text-body dark:text-gray-400">Watch messages flow across your channels live and publish your own from the interface. Debugging event-driven and real-time applications is finally built into the tool instead of requiring a separate terminal session.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">8. Built-in Command Console</h3>
                    <p class="text-body dark:text-gray-400">Run raw Redis commands straight from the dashboard with an interactive console and a persistent per-server command history. No need to SSH into the server or open a separate
                        <code>redis-cli</code> session &mdash; execute, inspect, and debug commands right where you manage your keys.
                    </p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">9. Read-only Mode & Built-in Login</h3>
                    <p class="text-body dark:text-gray-400">Need to share access with the team? Enable the optional login screen, or switch on the read-only mode that hides and blocks every destructive action &mdash; handy for production dashboards and demos.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">10. Beautiful Dark Mode</h3>
                    <p class="text-body dark:text-gray-400">A clean, responsive UI with a highly requested dark mode is built right in. It looks and feels like a modern developer tool should.</p>
                </div>
            </div>
        </div>
    </section>

    <div class="bg-surface border-y border-line-soft dark:bg-white/[0.02] dark:border-ink-line">
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
