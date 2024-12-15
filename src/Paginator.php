<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

readonly class Paginator {
    private int $total;

    /**
     * @var array<int|string, mixed>
     */
    private array $paginated;

    private int $page;

    private int $per_page;

    /**
     * @param array<int, array<string, int|string>> $items
     * @param array<int, array<int|string, string>> $url
     */
    public function __construct(
        private Template $template,
        array            $items,
        private array    $url = [['pp', 's'], ['p' => '']]
    ) {
        $this->total = count($items);
        $this->page = Http::get('p', 1);
        $this->per_page = Http::get('pp', 50);
        $this->paginated = array_slice($items, $this->per_page * ($this->page - 1), $this->per_page, true);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getPaginated(): array {
        return $this->paginated;
    }

    /**
     * @return array<int, int>
     */
    public function getPages(): array {
        $pages = [];

        $total_pages = (int) ceil($this->total / $this->per_page);
        $show_pages = 3; // number of pages displayed before and after the current page

        if ($total_pages > 1 && $this->page <= $total_pages) {
            $pages[] = 1; // always show the first page

            $i = max(2, $this->page - $show_pages);

            if ($i > 2) {
                $pages[] = '..';
            }

            $min = min($this->page + ($show_pages + 1), $total_pages);

            for (; $i < $min; $i++) {
                $pages[] = $i;
            }

            if ($i !== $total_pages) {
                $pages[] = '..';
            }

            $pages[] = $total_pages; // always show the last page
        }

        return $pages;
    }

    public function render(): string {
        $on_page = $this->paginated !== [] ? 1 : 0;
        $select = [50, 100, 200, 300, 400, 500];

        return $this->template->render('components/paginator', [
            'first_on_page' => Format::number((int) array_key_first($this->paginated) + $on_page),
            'last_on_page'  => Format::number((int) array_key_last($this->paginated) + $on_page),
            'total'         => Format::number($this->total),
            'current_page'  => $this->page,
            'per_page'      => $this->per_page,
            'select'        => array_combine($select, $select),
            'url'           => Http::queryString($this->url[0], $this->url[1]),
            'pages'         => $this->getPages(),
        ]);
    }
}
