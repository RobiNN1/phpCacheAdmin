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

if (!function_exists('asset')) {
    function asset(string $path): string {
        $file = __DIR__.'/../'.ltrim($path, '/');
        $version = is_file($file) ? filemtime($file) : time();

        return $path.'?v='.$version;
    }
}

if (!function_exists('command_block')) {
    /**
     * @param array<int, string> $lines_html Pre-highlighted HTML, one entry per displayed line.
     * @param string             $copy_text  Single-line command placed on the clipboard.
     */
    function command_block(array $lines_html, string $copy_text): string {
        $html = '<div class="relative rounded-box bg-ink">';
        $html .= '<button type="button" class="copy-btn absolute top-3 right-3 p-2 rounded-lg border transition-colors cursor-pointer text-slate-400 bg-white/5 border-white/10 hover:text-white hover:bg-white/15" data-copy="'.htmlspecialchars($copy_text, ENT_QUOTES).'" title="Copy to clipboard">';
        $html .= svg('copy', 16, 'icon-copy').svg('check', 16, 'icon-check text-emerald-300');
        $html .= '<span class="sr-only">Copy to clipboard</span>';
        $html .= '</button>';
        $html .= '<div class="overflow-x-auto p-6 pr-16"><code class="block font-mono text-sm leading-loose whitespace-nowrap text-slate-200">';

        $last = count($lines_html) - 1;

        foreach ($lines_html as $i => $line) {
            $indent = $i > 0 ? ' pl-6' : '';
            $continuation = $i < $last ? ' <span class="text-slate-500">\</span>' : '';
            $html .= '<span class="block'.$indent.'">'.$line.$continuation.'</span>';
        }

        return $html.'</code></div></div>';
    }
}

if (!function_exists('compare_cell')) {
    function compare_cell(bool|string $value, bool $positive_column): string {
        if ($value === true) {
            return '<span class="inline-flex justify-center text-emerald-500">'.svg('check', 18).'<span class="sr-only">Yes</span></span>';
        }

        if ($value === false) {
            return '<span class="inline-flex justify-center text-gray-300 dark:text-gray-600">'.svg('x', 14).'<span class="sr-only">No</span></span>';
        }

        $color = $positive_column ? 'text-slate-700 dark:text-slate-300' : 'text-muted dark:text-gray-500';

        return '<span class="'.$color.'">'.$value.'</span>';
    }
}

if (!function_exists('ld_json')) {
    function ld_json(array $schema): string {
        return '<script type="application/ld+json">'.json_encode($schema, JSON_UNESCAPED_SLASHES).'</script>';
    }
}

if (!function_exists('faq_schema')) {
    function faq_schema(array $faqs): array {
        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => array_map(static fn (array $faq): array => [
                '@type'          => 'Question',
                'name'           => $faq[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($faq[1]), ENT_QUOTES)))],
            ], $faqs),
        ];
    }
}

if (!function_exists('breadcrumb_schema')) {
    function breadcrumb_schema(string $name, string $url): array {
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://phpcacheadmin.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $name, 'item' => $url],
            ],
        ];
    }
}

$page_title = $page_title ?? 'phpCacheAdmin - Modern GUI for Redis, Memcached, OPCache & APCu';
$page_desc = $page_desc ?? 'Modern dashboard & manager for Redis, Memcached, APCu, OPCache and Realpath. A Docker-ready alternative to phpRedisAdmin & opcache-gui with Cluster & ACL.';
$canonical_url = $canonical_url ?? 'https://phpcacheadmin.com/';
$page_keywords = $page_keywords ?? 'phpCacheAdmin, Redis GUI, Redis Manager, Redis web interface, Memcached Admin, Memcached Manager, OPCache GUI, OPCache Manager, APCu Dashboard, APCu Manager, Realpath Cache, phpRedisAdmin alternative, Redis Commander alternative, Redis Cluster, Docker, PHP cache manager';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <meta name="description" content="<?php echo $page_desc; ?>">
    <meta name="keywords" content="<?php echo $page_keywords; ?>">
    <meta name="robots" content="index, follow">
    <meta name="author" content="RobiNN1">
    <link rel="canonical" href="<?php echo $canonical_url; ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo $canonical_url; ?>">
    <meta property="og:title" content="<?php echo $page_title; ?>">
    <meta property="og:description" content="<?php echo $page_desc; ?>">
    <meta property="og:image" content="https://phpcacheadmin.com/assets/img/og-image.png">
    <meta property="og:site_name" content="phpCacheAdmin">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $page_title; ?>">
    <meta name="twitter:description" content="<?php echo $page_desc; ?>">
    <meta name="twitter:image" content="https://phpcacheadmin.com/assets/img/og-image.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon.png">
    <meta name="theme-color" content="#ffffff">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
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
      "sameAs": [
        "https://github.com/RobiNN1/phpCacheAdmin",
        "https://hub.docker.com/r/robinn/phpcacheadmin",
        "https://packagist.org/packages/robinn/phpcacheadmin",
        "https://alternativeto.net/software/phpcacheadmin/about/"
      ],
      "author": {
        "@type": "Person",
        "name": "RobiNN1"
      }
    }
    </script>
</head>
<body class="overflow-x-hidden antialiased transition-colors duration-300 bg-white text-body dark:bg-ink dark:text-slate-300">
<nav class="fixed top-0 z-50 w-full border-b border-line-soft bg-white/90 backdrop-blur-xl dark:border-b-ink-line dark:bg-ink/90">
    <div class="px-4 mx-auto max-w-7xl">
        <div class="flex flex-wrap justify-between items-center lg:text-xl">
            <a class="inline-block py-3" href="/" aria-label="Link to this site">
                <?php echo svg('../logo', null, 'h-5 md:h-10 w-auto'); ?>
            </a>

            <div class="flex items-center py-3 md:hidden">
                <button id="toggle-menu" type="button" class="text-body dark:text-gray-300">
                    <?php echo svg('menu', 24); ?>
                    <span class="sr-only">Toggle menu</span>
                </button>
            </div>

            <div class="hidden order-last w-full md:flex md:w-auto md:order-0" id="menu">
                <div class="flex flex-col gap-6 items-center py-4 w-full md:flex-row md:gap-8 md:py-0">
                    <div class="flex gap-3 justify-center">
                        <div class="flex p-1 gap-0.5 h-10 items-center rounded-btn bg-fill dark:bg-white/5 [&>.active]:bg-white dark:[&>.active]:bg-ink-soft [&>.active]:text-ink dark:[&>.active]:text-white [&>.active]:shadow-btn">
                            <button class="flex justify-center items-center w-8 h-8 rounded-lg transition-colors cursor-pointer text-muted dark:text-gray-400 hover:text-ink dark:hover:text-gray-200" type="button" data-theme="light" title="Light">
                                <?php echo svg('sun'); ?>
                            </button>
                            <button class="flex justify-center items-center w-8 h-8 rounded-lg transition-colors cursor-pointer text-muted dark:text-gray-400 hover:text-ink dark:hover:text-gray-200" type="button" data-theme="dark" title="Dark">
                                <?php echo svg('moon'); ?>
                            </button>
                            <button class="flex justify-center items-center w-8 h-8 rounded-lg transition-colors cursor-pointer text-muted dark:text-gray-400 hover:text-ink dark:hover:text-gray-200" type="button" data-theme="system" title="System">
                                <?php echo svg('system'); ?>
                            </button>
                        </div>

                        <a href="https://github.com/RobiNN1/phpCacheAdmin" target="_blank" rel="noopener noreferrer" class="flex gap-2 justify-center items-center px-4 h-10 text-sm font-semibold text-white rounded-btn transition-colors bg-ink hover:bg-slate-700 dark:text-ink dark:bg-white dark:hover:bg-slate-200">
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
