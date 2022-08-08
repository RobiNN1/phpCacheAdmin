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

class Paginator {
    /**
     * @var Template
     */
    private Template $template;

    /**
     * @var int
     */
    private int $total;

    /**
     * @var array<int|string, mixed>
     */
    private array $paginated;

    /**
     * @var int
     */
    private int $page;

    /**
     * @var int
     */
    private int $per_page;

    /**
     * @var array<int, mixed>
     */
    private array $url = [
        ['pp'],
        ['p' => ''],
    ];

    /**
     * @param Template                         $template
     * @param array<int, array<string, mixed>> $items
     */
    public function __construct(Template $template, array $items) {
        $this->template = $template;
        $this->total = count($items);
        $this->page = Http::get('p', 'int', 1);
        $this->per_page = Http::get('pp', 'int', 25);
        $this->paginated = array_slice($items, $this->per_page * ($this->page - 1), $this->per_page, true);
    }

    /**
     * Get paginated items.
     *
     * @return array<int|string, mixed>
     */
    public function getPaginated(): array {
        return $this->paginated;
    }

    /**
     * Set Http::queryString() options.
     *
     * @param array<int, mixed> $queries
     *
     * @return void
     */
    public function setUrl(array $queries): void {
        $this->url = $queries;
    }

    /**
     * Get pages for paginator.
     *
     * @return array<int, int>
     */
    private function getPages(): array {
        static $pages = [];

        $total_pages = (int) ceil($this->total / $this->per_page);

        if ($total_pages > 1 && $this->page <= $total_pages) {
            $pages[] = 1; // always show first page

            $i = max(2, $this->page - 3);

            if ($i > 2) {
                $pages[] = '...';
            }

            for (; $i < min($this->page + 5, $total_pages); $i++) {
                $pages[] = $i;
            }

            if ($i !== $total_pages) {
                $pages[] = '...';
            }

            $pages[] = $total_pages; // always show last page
        }

        return $pages;
    }

    /**
     * Render paginator.
     *
     * @return string
     */
    public function render(): string {
        $on_page = count($this->paginated);

        $select = [25, 50, 100, 200, 300, 400];

        return $this->template->render('components/paginator', [
            'first_on_page' => Helpers::formatNumber(array_key_first($this->paginated) + ($on_page > 0 ? 1 : 0)),
            'last_on_page'  => Helpers::formatNumber(array_key_last($this->paginated) + ($on_page > 0 ? 1 : 0)),
            'total'         => Helpers::formatNumber($this->total),
            'current_page'  => $this->page,
            'per_page'      => $this->per_page,
            'select'        => array_combine($select, $select),
            'url'           => Http::queryString($this->url[0], $this->url[1]),
            'pages'         => $this->getPages(),
        ]);
    }
}
