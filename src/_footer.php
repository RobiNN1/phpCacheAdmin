<?php
declare(strict_types=1);
?>
</main>

<footer class="py-10 px-4 mx-auto max-w-7xl">
    <div class="flex flex-wrap justify-center gap-6 pb-8 border-b border-line dark:border-ink-line">
        <?php
        $footer_links = [
            'phpredisadmin-alternative.php'     => 'phpRedisAdmin Alternative',
            'redis-commander-alternative.php'   => 'Redis Commander Alternative',
            'opcache-gui-alternative.php'       => 'opcache-gui Alternative',
            'phpmemcachedadmin-alternative.php' => 'phpMemcachedAdmin Alternative',
        ];

        foreach ($footer_links as $url => $title) {
            echo '<a href="'.$url.'" class="text-sm font-medium text-muted hover:text-blue-700 dark:text-gray-400 dark:hover:text-blue-400 transition-colors">'.$title.'</a>';
        }
        ?>
    </div>

    <div class="flex flex-col gap-4 justify-between items-center pt-8 md:flex-row">
        <div class="text-sm text-body dark:text-gray-400">
            &copy; <span id="year"></span> phpCacheAdmin. Open source under MIT License.
        </div>
        <div class="text-sm text-body dark:text-gray-400">
            Made by
            <a href="https://github.com/RobiNN1" target="_blank" rel="noopener noreferrer" class="font-semibold transition-colors hover:text-gray-900 dark:hover:text-white">RobiNN1</a>
        </div>
    </div>
</footer>
<script>document.getElementById('year').innerText = String(new Date().getFullYear());</script>
<script src="assets/js/scripts.js?v=<?php echo filemtime(__DIR__.'/../assets/js/scripts.js'); ?>"></script>
</body>
</html>
