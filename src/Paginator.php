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
     * @var array
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
     * @var array
     */
    private array $select;

    /**
     * @var array
     */
    private array $url = [
        ['pp'],
        ['p' => ''],
    ];

    /**
     * @param Template $template
     * @param array    $items
     * @param int      $default_per_page
     */
    public function __construct(Template $template, array $items, int $default_per_page = 15) {
        $this->template = $template;

        $this->total = count($items);

        $page = (int) Http::get('p', 'int');
        $this->page = !empty($page) ? $page : 1;

        $per_page = (int) Http::get('pp', 'int');
        $this->per_page = !empty($per_page) ? $per_page : $default_per_page;

        $this->paginated = array_slice($items, $this->per_page * ($this->page - 1), $this->per_page, true);

        $this->select = [15, 25, 50, 100, 200];
    }

    /**
     * Get paginated items.
     *
     * @param bool $sort Sort keys by key name.
     *
     * @return array
     */
    public function getPaginated(bool $sort = false): array {
        if ($sort) {
            usort($this->paginated, static fn ($a, $b) => strcmp((string) $a['key'], (string) $b['key']));
        }

        return $this->paginated;
    }

    /**
     * Set select options (for per page).
     *
     * @param array $select
     *
     * @return void
     */
    public function setSelect(array $select): void {
        $this->select = $select;
    }

    /**
     * Set Http::queryString() options.
     *
     * @param array $queries
     *
     * @return void
     */
    public function setUrl(array $queries): void {
        $this->url = $queries;
    }

    /**
     * Get pages for paginator.
     *
     * @return array
     */
    private function getPages(): array {
        static $pages = [];

        $total_pages = (int) ceil($this->total / $this->per_page);

        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i === $this->page) {
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
