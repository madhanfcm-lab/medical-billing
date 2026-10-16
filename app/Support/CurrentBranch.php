<?php

namespace App\Support;

use App\Models\Branch;

/**
 * Which branch the signed-in person is working in right now.
 *
 * Set once per request by the ResolveBranch middleware. Until then (console, seeders, queue jobs) nothing is
 * filtered, so scripts still see the whole hospital. "All branches" (id null) is a read-only-ish overview for people
 * who work in more than one branch; new records made in that mode go to their home branch.
 */
class CurrentBranch
{
    private bool $resolved = false;

    private ?Branch $branch = null;

    private ?Branch $home = null;

    public function set(?Branch $branch, Branch $home): void
    {
        $this->resolved = true;
        $this->branch = $branch;
        $this->home = $home;
    }

    public function clear(): void
    {
        $this->resolved = false;
        $this->branch = $this->home = null;
    }

    /** True once a request has picked a branch (or "all"); false in scripts. */
    public function resolved(): bool
    {
        return $this->resolved;
    }

    public function isAll(): bool
    {
        return $this->resolved && $this->branch === null;
    }

    /** The branch to filter by, or null for no filter. */
    public function filterId(): ?int
    {
        return $this->branch?->id;
    }

    public function model(): ?Branch
    {
        return $this->branch ?? $this->home;
    }

    /** The branch a new record belongs to. */
    public function writeId(): ?int
    {
        return $this->branch?->id ?? $this->home?->id ?? Branch::main()?->id;
    }
}
