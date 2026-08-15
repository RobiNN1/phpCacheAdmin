<?php
declare(strict_types=1);

$page_title = 'Redis Commander Alternative - phpCacheAdmin | Redis Web GUI without Node.js';
$page_desc = 'Compare Redis Commander vs phpCacheAdmin. A Redis web GUI that runs on PHP instead of Node.js, with a MONITOR profiler, Pub/Sub, Slowlog, latency insights and Docker.';
$canonical_url = 'https://phpcacheadmin.com/redis-commander-alternative';
$page_keywords = 'Redis Commander alternative, Redis Commander vs phpCacheAdmin, redis-commander alternative, Redis web GUI, Redis GUI without Node.js, Redis browser, Redis management tool, Redis admin panel, phpCacheAdmin, replace Redis Commander';

require __DIR__.'/_header.php';

$comparison = [
    ['Runtime', 'PHP 8.2+ on your web server', 'Node.js process (>= 12)'],
    ['Installation', 'Unzip, Composer or Docker', 'npm install -g or Docker'],
    ['Redis Cluster', true, true],
    ['Redis Sentinel', true, true],
    ['Redis ACL (username + password)', true, true],
    ['Large databases', 'SCAN engine, millions of keys', 'SCAN engine'],
    ['Table or tree view of keys', true, 'Tree view'],
    ['Import & export', true, true],
    ['Built-in command console', true, true],
    ['All data types incl. Vector Sets (Redis 8)', true, 'No Vector Sets'],
    ['JSON (RedisJSON)', 'View & edit', 'View only'],
    ['Stream consumer groups (pending, lag)', true, 'Stream entries only'],
    ['Per-field hash TTLs (Redis 7.4)', true, false],
    ['Live command profiler (MONITOR)', true, false],
    ['Pub/Sub monitoring & publishing', true, false],
    ['Slowlog inspector', true, false],
    ['Latency monitor & LATENCY / MEMORY DOCTOR', true, false],
    ['Connected clients (list & disconnect)', true, false],
    ['Keyspace analysis', true, false],
    ['Metrics & health checks', 'SQLite-backed history', false],
    ['Formatted, raw & hex value view', true, 'Binary as hex option'],
    ['Read-only mode', true, true],
    ['Built-in login', 'Optional user accounts', 'HTTP basic auth, JWT SSO'],
    ['Memcached, OPCache, APCu & Realpath', 'Same dashboard', 'Redis only'],
    ['Dark mode', true, false],
    ['Docker image', true, true],
    ['License', 'MIT', 'MIT'],
];

$faqs = [
    [
        'What is the difference between phpCacheAdmin and Redis Commander?',
        'Redis Commander is a Redis web interface written in Node.js, so it runs as a separate service next to your application. phpCacheAdmin runs on the PHP stack you already have - unzip it into your web root, install it with Composer or run the Docker image. On top of key browsing and editing it adds a MONITOR-based profiler, Pub/Sub monitoring, a Slowlog inspector, latency insights, keyspace analysis and historical metrics, plus dashboards for Memcached, OPCache, APCu and the realpath cache.',
    ],
    [
        'Do I need Node.js to run phpCacheAdmin?',
        'No. phpCacheAdmin needs PHP 8.2+ and nothing else - no Node.js runtime, no npm packages, no database. If you already serve a PHP application, the dashboard runs on the same server; otherwise the Docker image starts it in one command.',
    ],
    [
        'Is Redis Commander still maintained?',
        'It still receives occasional commits, but releases are rare: version 0.9.0 was published in May 2025 and the release before it in May 2022. Newer Redis features such as Vector Sets, per-field hash TTLs and stream consumer groups are not covered. phpCacheAdmin is actively developed and follows new Redis releases.',
    ],
    [
        'Does phpCacheAdmin support Redis Cluster, Sentinel and ACL like Redis Commander?',
        'Yes, all three. Both tools connect to Redis Cluster and Sentinel setups and authenticate with an ACL username and password. phpCacheAdmin works with both the phpredis extension and the bundled Predis client, and also with Redis-compatible servers such as Valkey and KeyDB.',
    ],
    [
        'Can I watch what Redis is doing in real time?',
        'Yes. The profiler streams commands live through MONITOR (every node at once in a cluster), the Pub/Sub tab shows messages as they are published and lets you publish your own, and the Slowlog and latency tabs surface slow commands, latency percentiles and the advice of LATENCY and MEMORY DOCTOR. Metrics are stored locally with SQLite, so charts also cover the time the dashboard was closed.',
    ],
    [
        'Do I have to migrate my data when switching?',
        'No. Both tools are only web interfaces on top of your Redis server; the data stays in Redis. Point phpCacheAdmin at the same host and port that Redis Commander used and all your keys appear immediately.',
    ],
    [
        'Is phpCacheAdmin free for commercial use?',
        'Yes. Both projects are open source under the MIT license, so they can be used freely in personal and commercial projects.',
    ],
];

echo ld_json(faq_schema($faqs));
echo ld_json(breadcrumb_schema('Redis Commander Alternative', $canonical_url));
?>

    <section class="px-4 pt-20 pb-10 mx-auto max-w-7xl md:pt-32">
        <div class="mb-16 text-center">
            <h1 class="mb-6 text-4xl font-semibold tracking-tight leading-[1.05] sm:text-5xl lg:text-6xl text-balance">
                A <span class="text-redis">Redis Commander</span> Alternative for PHP Stacks
            </h1>
            <p class="mx-auto mb-8 max-w-4xl text-lg leading-relaxed text-body sm:mb-10 sm:text-xl dark:text-gray-400 text-balance">
                phpCacheAdmin is a web GUI for Redis that runs on PHP instead of a separate Node.js service. Browse, edit and delete keys as you do today, and get a MONITOR profiler, Pub/Sub, Slowlog, latency insights and Memcached, OPCache and APCu dashboards in the same interface.
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
                <span class="ml-3 font-mono text-xs text-muted">localhost/?dashboard=redis</span>
            </div>
            <img loading="lazy" class="w-full dark:hidden" src="<?php echo asset('assets/img/preview/redis-light.webp'); ?>" alt="phpCacheAdmin Redis dashboard preview - light mode">
            <img loading="lazy" class="hidden w-full dark:block" src="<?php echo asset('assets/img/preview/redis-dark.webp'); ?>" alt="phpCacheAdmin Redis dashboard preview - dark mode">
        </div>
    </section>

    <div class="bg-surface border-y border-line-soft dark:bg-white/2 dark:border-ink-line">
        <section class="px-4 py-16 mx-auto max-w-5xl md:py-20" id="comparison">
            <div class="mb-10 text-center">
                <h2 class="mb-4 text-3xl font-semibold tracking-tight sm:text-4xl dark:text-white text-ink">Redis Commander vs. phpCacheAdmin</h2>
                <p class="mx-auto max-w-2xl text-lg text-body dark:text-gray-400">
                    A side-by-side look at what each tool covers.
                </p>
            </div>

            <div class="overflow-hidden bg-white rounded-panel border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                        <tr class="border-b border-line bg-surface dark:border-ink-line dark:bg-white/5">
                            <th class="py-4 px-6 font-semibold text-left text-ink dark:text-white">Feature</th>
                            <th class="py-4 px-6 font-semibold text-center text-blue-700 dark:text-blue-400">phpCacheAdmin</th>
                            <th class="py-4 px-6 font-semibold text-center text-muted dark:text-gray-400">Redis Commander</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($comparison as [$feature, $pca, $rc]) { ?>
                            <tr class="border-b border-line last:border-b-0 dark:border-ink-line">
                                <td class="py-3.5 px-6 font-medium text-slate-700 dark:text-slate-300"><?php echo $feature; ?></td>
                                <td class="py-3.5 px-6 text-center"><?php echo compare_cell($pca, true); ?></td>
                                <td class="py-3.5 px-6 text-center"><?php echo compare_cell($rc, false); ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="mt-6 text-sm text-center text-muted dark:text-gray-400">
                Based on Redis Commander 0.9.0, the latest release at the time of writing. There is also a
                <a href="phpredisadmin-alternative.php" class="font-medium text-blue-700 dark:text-blue-400 hover:underline">phpRedisAdmin vs phpCacheAdmin comparison</a>.
            </p>
        </section>
    </div>

    <section class="px-4 py-16 mx-auto max-w-5xl md:py-20">
        <div class="p-8 bg-white rounded-panel border border-line shadow-card dark:bg-ink-soft dark:border-ink-line">
            <h2 class="mb-8 text-2xl font-semibold tracking-tight dark:text-white text-ink">What phpCacheAdmin adds</h2>

            <div class="space-y-8">
                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">1. No Node.js Service to Run</h3>
                    <p class="text-body dark:text-gray-400">Redis Commander is a Node.js application, so it needs its own runtime, its own process manager and its own npm dependency updates. phpCacheAdmin is plain PHP 8.2+: drop the release zip into your web root, require it with Composer, or start the Docker image. Nothing new to keep running next to your stack.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">2. Live Diagnostics, Not Only a Key Browser</h3>
                    <p class="text-body dark:text-gray-400">Watch commands as they run with the MONITOR-based profiler (across every cluster node at once), inspect the Slowlog, read latency monitor events and per-command percentiles, and get the advice of LATENCY and MEMORY DOCTOR without opening
                        <code>redis-cli</code>.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">3. Pub/Sub Built In</h3>
                    <p class="text-body dark:text-gray-400">Browse active channels, subscribe to them and publish your own messages straight from the dashboard &mdash; useful for debugging event-driven applications that Redis Commander leaves to a separate terminal.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">4. Keyspace Analysis & Historical Metrics</h3>
                    <p class="text-body dark:text-gray-400">The analysis tab groups the keyspace by prefix, type and expiry and adds the size distribution Redis 8 tracks on its own. Metrics are stored locally with SQLite, so memory, hit rate and eviction charts also cover the hours nobody had the dashboard open, and health checks flag memory pressure, low hit rates, evictions, client saturation, persistence and replication problems.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">5. Redis 8 and 7.4 Data Types</h3>
                    <p class="text-body dark:text-gray-400">Every data type is supported, including Vector Sets from Redis 8 and per-field hash TTLs from Redis 7.4. Stream consumer groups are shown with their consumers, pending entries, lag and the oldest entry still waiting for an acknowledgement. Values can be shown formatted, raw or as a hex dump.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">6. More Than Redis</h3>
                    <p class="text-body dark:text-gray-400">The same interface manages Memcached, OPCache, APCu and PHP's realpath cache, and a Server dashboard shows PHP configuration, extensions,
                        <code>phpinfo()</code>, CPU, RAM and disk usage. One tool instead of one per cache.</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-redis mb-2">7. Familiar Ground</h3>
                    <p class="text-body dark:text-gray-400">The things Redis Commander users rely on are all here: Redis Cluster, Sentinel and ACL connections, SCAN on large databases, a tree view folded on a configurable separator, import and export, an interactive command console with per-server history, and a read-only mode for shared or production instances.</p>
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
