<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BranchSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate(['branch' => ['required']]);

        $branches = $request->user()->accessibleBranches();
        $choice = (string) $request->input('branch');

        if ($choice === 'all' && $branches->count() > 1) {
            $request->session()->put(ResolveBranch::SESSION_KEY, 'all');
            $name = 'All branches';
        } else {
            $branch = $branches->firstWhere('id', (int) $choice);
            abort_unless($branch, 403, 'You do not have access to that branch.');
            $request->session()->put(ResolveBranch::SESSION_KEY, $branch->id);
            $name = $branch->name;
        }

        // A page for one record (/patients/5/...) may not exist in the other branch, so only list pages are returned to.
        $back = url()->previous();
        $path = parse_url($back, PHP_URL_PATH) ?: '/';
        $target = preg_match('#/\d+(/|$)#', $path) || str_contains($path, '/create') ? route('dashboard') : $back;

        return redirect($target)->with('success', "Now working in {$name}.");
    }
}
