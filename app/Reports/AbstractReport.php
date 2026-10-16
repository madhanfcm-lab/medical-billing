<?php

namespace App\Reports;

use App\Models\Branch;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

abstract class AbstractReport implements Report
{
    public function filters(): array
    {
        return ['period'];
    }

    public function defaultsToThisMonth(): bool
    {
        return true;
    }

    public function statusOptions(): array
    {
        return [];
    }

    /**
     * Fetch rows for a detail section, capped for the on-screen view.
     *
     * @return array{0: Collection, 1: int} the rows, and how many exist in total
     */
    protected function limited(Builder $query, ?int $limit): array
    {
        $total = (clone $query)->toBase()->getCountForPagination();

        return [$limit === null ? $query->get() : $query->limit($limit)->get(), $total];
    }

    /** Sum of money values without float drift. @param  iterable<mixed>  $values */
    protected function sumMoney(iterable $values): string
    {
        $paise = 0;
        foreach ($values as $v) {
            $paise += to_paise($v);
        }

        return from_paise($paise);
    }

    protected function col(string $key, string $label, string $type = 'text'): array
    {
        return ReportSection::column($key, $label, $type);
    }

    /** Wrap sections into a result; the subtitle lists the period (when the report uses one) and any extra filters. */
    protected function result(string $extra, ReportFilters $f, array $summary, array $sections): ReportResult
    {
        $parts = array_filter([$this->branchLabel(), in_array('period', $this->filters(), true) ? $f->periodLabel() : null, $extra]);

        return new ReportResult($this->title(), implode(' · ', $parts), $summary, $sections);
    }

    /** "Branch: North Branch" or "All branches": printed on the report so a total is never mistaken for another branch's. Blank with one branch. */
    private function branchLabel(): ?string
    {
        $current = app(CurrentBranch::class);
        if (! $current->resolved() || Branch::query()->count() < 2) {
            return null;
        }

        return $current->isAll() ? 'All branches' : 'Branch: '.$current->model()?->name;
    }
}
