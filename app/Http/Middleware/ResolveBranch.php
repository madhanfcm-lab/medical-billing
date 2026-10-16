<?php

namespace App\Http\Middleware;

use App\Support\CurrentBranch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides which branch this request works in: the one chosen in the top-bar switcher (kept in the session) if the
 * person may use it, otherwise their first branch. From here on every branch-scoped query is filtered by it.
 */
class ResolveBranch
{
    public const SESSION_KEY = 'branch_id';

    public function __construct(private readonly CurrentBranch $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $branches = $user->accessibleBranches();

        abort_if($branches->isEmpty(), 403, 'No branch is assigned to your login. Ask the administrator to give you a branch.');

        $home = $branches->sortBy('id')->first();   // the oldest branch they may use is where they start
        $chosen = $request->session()->get(self::SESSION_KEY);

        $branch = $chosen === 'all' && $branches->count() > 1
            ? null
            : ($branches->firstWhere('id', (int) $chosen) ?? $home);

        $this->current->set($branch, $home);

        View::share('branchSwitcher', ['branches' => $branches, 'current' => $branch, 'all' => $branch === null]);

        return $next($request);
    }

    /** Nothing after the response (jobs, the test that made the request) should inherit this request's branch. */
    public function terminate(Request $request, Response $response): void
    {
        $this->current->clear();
    }
}
