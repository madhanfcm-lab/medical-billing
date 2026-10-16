<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Sends requests shaped like the real DataTables client (with the columns[] array),
 * so server-side search and ordering are genuinely exercised.
 */
trait CallsDataTables
{
    /**
     * @param  list<string>  $columns  column names in table order
     * @param  list<string>  $notSearchable  columns sent with searchable=false (as the page JS does)
     * @param  array<string, mixed>  $extra  extra query params (filters, tab, ...)
     */
    protected function dt(string $url, array $columns, string $search = '', array $extra = [], array $notSearchable = [], int $orderColumn = 0, string $dir = 'asc'): TestResponse
    {
        $cols = [];
        foreach ($columns as $i => $name) {
            $cols[$i] = [
                'data' => $name, 'name' => '', 'orderable' => 'true',
                'searchable' => in_array($name, $notSearchable, true) ? 'false' : 'true',
                'search' => ['value' => '', 'regex' => 'false'],
            ];
        }

        return $this->getJson($url.(str_contains($url, '?') ? '&' : '?').http_build_query($extra + [
            'draw' => 1, 'start' => 0, 'length' => 50, 'columns' => $cols,
            'order' => [['column' => $orderColumn, 'dir' => $dir]],
            'search' => ['value' => $search, 'regex' => 'false'],
        ]), ['X-Requested-With' => 'XMLHttpRequest']);
    }
}
