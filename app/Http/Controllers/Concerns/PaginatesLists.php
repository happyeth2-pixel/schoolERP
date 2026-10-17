<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * One small, shared shape for every screen-list that paginates at the database level: a bounded, optionally
 * caller-adjustable page size (?per_page=, clamped so nobody can ask for the whole table at once) and the plain
 * {current_page, last_page, per_page, total, from, to} numbers a page needs to draw "Showing 1-25 of 1,248" and
 * its Previous/Next controls. The paginator's own `data` is deliberately NOT passed to the browser as-is --
 * callers map it to the same flat row shape the page already expected, and send this meta alongside it under its
 * own prop, so existing pages/tests that read the rows directly are unaffected by adding real pagination.
 */
trait PaginatesLists
{
    protected function perPage(Request $request, int $default = 25, int $max = 100): int
    {
        $requested = (int) $request->query('per_page', $default);

        return max(1, min($max, $requested ?: $default));
    }

    /** @return array{current_page:int,last_page:int,per_page:int,total:int,from:?int,to:?int} */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
