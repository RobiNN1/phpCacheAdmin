<?php
/**
 * This file is part of phpCacheAdmin.
 *
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use Exception;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class Template {
    /**
     * @var array
     */
    private array $tpl_globals = [];

    /**
     * Add global template variable.
     *
     * @param string $name
     * @param mixed  $value
     *
     * @return void
     */
    public function addTplGlobal(string $name, $value): void {
        $this->tpl_globals[$name] = $value;
    }

    /**
     * Render template.
     *
     * @param string $tpl
     * @param array  $data
     *
     * @return string
     */
    public function render(string $tpl, array $data = []): string {
        try {
            $loader = new FilesystemLoader(__DIR__.'/../templates');
            $twig = new Environment($loader, [
                'cache' => __DIR__.'/../cache',
                'debug' => Admin::getConfig('twigdebug'),
            ]);

            if (Admin::getConfig('twigdebug')) {
                $twig->addExtension(new DebugExtension());
            }

            $twig->addFunction(new TwigFunction('svg', [Helpers::class, 'svg'], ['is_safe' => ['html']]));
            $twig->addFunction(new TwigFunction('truncate', [Helpers::class, 'truncate']));

            $twig->addFilter(new TwigFilter('format_seconds', [Helpers::class, 'formatSeconds']));

            foreach ($this->tpl_globals as $name => $value) {
                $twig->addGlobal($name, $value);
            }

            return $twig->render($tpl.'.twig', $data);
        } catch (Exception $e) {
            return $e->getMessage().' in '.$e->getFile().' at line: '.$e->getLine();
        }
    }
}
