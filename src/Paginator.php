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
     * @var array<int, int>
     */
    private array $select;

    /**
     * @var array<mixed, mixed>
     */
    private array $url = [
        ['pp'],
        ['p' => ''],
    ];

    /**
     * @param Template                          $template
     * @param array<int, array<string, string>> $items
     * @param int                               $default_per_page
     */
    public function __construct(Template $template, array $items, int $default_per_page = 25) {
        $this->template = $template;

        $this->total = count($items);

        $page = (int) Http::get('p', 'int');
        $this->page = empty($page) ? 1 : $page;

        $per_page = (int) Http::get('pp', 'int');
        $this->per_page = empty($per_page) ? $default_per_page : $per_page;

        $this->paginated = array_slice($items, $this->per_page * ($this->page - 1), $this->per_page, true);

        $this->select = [25, 50, 100, 200, 300];
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
     * Set select options (for per page).
     *
     * @param array<int, int> $select
     *
     * @return void
     */
    public function setSelect(array $select): void {
        $this->select = $select;
    }

    /**
     * Set Http::queryString() options.
     *
     * @param array<mixed, mixed> $queries
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

        for ($i = 1; $i <= $total_pages; $i++) {
            if ($this->page === $i) {
                $pages[] = $this->page;
            } else {
                $pages[] = $i;
            }
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

        return $this->template->render('components/paginator', [
            'first_on_page' => array_key_first($this->paginated) + ($on_page > 0 ? 1 : 0),
            'last_on_page'  => array_key_last($this->paginated) + ($on_page > 0 ? 1 : 0),
            'total'         => $this->total,
            'current'       => $this->page,
            'per_page'      => $this->per_page,
            'select'        => array_combine($this->select, $this->select),
            'url'           => Http::queryString($this->url[0], $this->url[1]),
            'pages'         => $this->getPages(),
        ]);
    }
}
