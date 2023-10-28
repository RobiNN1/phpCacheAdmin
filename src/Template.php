<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use Exception;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class Template {
    /**
     * @var array<string, int|string>
     */
    private array $globals = [];

    /**
     * @var array<string, string>
     */
    private array $paths = [];

    /**
     * Add global Twig variable.
     *
     * @param mixed $value
     */
    public function addGlobal(string $name, $value): void {
        $this->globals[$name] = $value;
    }

    /**
     * Add a path with templates.
     *
     * @link https://twig.symfony.com/doc/3.x/api.html#built-in-loaders
     */
    public function addPath(string $namespace, string $path): void {
        $this->paths[$namespace] = $path;
    }

    private function initTwig(): Environment {
        $loader = new FilesystemLoader(__DIR__.'/../templates');
        $twig = new Environment($loader, [
            'cache' => Config::get('twig-cache', __DIR__.'/../tmp'),
            'debug' => Config::get('twig-debug', false),
        ]);

        foreach ($this->paths as $namespace => $path) {
            try {
                if ($path = realpath($path)) {
                    $loader->addPath($path, $namespace);
                }
            } catch (LoaderError $e) {
                echo $e->getMessage();
            }
        }

        if (Config::get('twig-debug', false)) {
            $twig->addExtension(new DebugExtension());
        }

        $twig->addFunction(new TwigFunction('svg', [Helpers::class, 'svg'], ['is_safe' => ['html']]));

        $twig->addFilter(new TwigFilter('space', static function (?string $value, bool $right = false): string {
            $right_side = $right ? $value.' ' : ' '.$value;

            return $value !== null && $value !== '' ? $right_side : '';
        }, ['is_safe' => ['html']]));

        $twig->addFilter(new TwigFilter('number', [Format::class, 'number']));
        $twig->addFilter(new TwigFilter('bytes', [Format::class, 'bytes']));
        $twig->addFilter(new TwigFilter('time', [Format::class, 'time']));
        $twig->addFilter(new TwigFilter('base64', static fn (string $string): string => base64_encode($string)));

        foreach ($this->globals as $name => $value) {
            $twig->addGlobal($name, $value);
        }

        return $twig;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $tpl, array $data = [], bool $string = false): string {
        $twig = $this->initTwig();

        try {
            if ($string) {
                return $twig->createTemplate($tpl)->render($data);
            }

            return $twig->render($tpl.'.twig', $data);
        } catch (Exception $e) {
            return $e->getMessage().' in '.$e->getFile().' at line: '.$e->getLine();
        }
    }
}
