<?php
declare(strict_types=1);

$pages = [
    'index.php',
    'phpredisadmin-alternative.php',
    'opcache-gui-alternative.php',
    'phpmemcachedadmin-alternative.php',
];

$base_url = 'https://phpcacheadmin.com';
$sitemap = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
$sitemap .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

foreach ($pages as $page) {
    $output_file = str_replace('.php', '.html', $page);

    ob_start();
    require __DIR__.'/'.$page;
    $html = ob_get_clean();

    $html = preg_replace('/\s+/', ' ', $html);
    $html = preg_replace('//', '', $html);

    $html = preg_replace('/href="index\.php"/', 'href="/"', $html);
    $html = preg_replace('/href="([a-zA-Z0-9_-]+)\.php"/', 'href="$1"', $html);

    if (file_put_contents(__DIR__.'/../'.$output_file, $html) !== false) {
        echo "Generated: $output_file\n";
    } else {
        echo "ERROR generating: $output_file\n";
    }

    $loc = $page === 'index.php' ? $base_url.'/' : $base_url.'/'.str_replace('.php', '', $page);

    $sitemap .= "  <url>\n";
    $sitemap .= "    <loc>$loc</loc>\n";
    $sitemap .= "    <lastmod>".date('Y-m-d')."</lastmod>\n";
    $sitemap .= "  </url>\n";
}

$sitemap .= '</urlset>';

if (file_put_contents(__DIR__.'/../sitemap.xml', $sitemap) !== false) {
    echo "Generated: sitemap.xml\n";
} else {
    echo "ERROR generating: sitemap.xml\n";
}
