<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AuditLogController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            $query = ActivityLog::query()
                ->with(['user:id,name', 'subject' => fn (MorphTo $m) => $m->withTrashed()])
                ->select('activity_logs.*')
                ->when($request->filled('type'), fn ($q) => $q->where('subject_type', $request->input('type')))
                ->when($request->filled('event'), fn ($q) => $q->where('event', $request->input('event')))
                ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('to')));

            return DataTables::eloquent($query)
                ->editColumn('created_at', fn (ActivityLog $l) => '<span class="text-nowrap">'.format_datetime($l->created_at).'</span>')
                ->addColumn('user', fn (ActivityLog $l) => e($l->user?->name ?? 'System'))
                ->editColumn('event', fn (ActivityLog $l) => '<span class="badge '.$l->event_badge.'">'.e(ucfirst($l->event)).'</span>')
                ->addColumn('record', function (ActivityLog $l) {
                    $url = $l->subjectUrl();
                    $label = e($l->subject_label);

                    return '<div class="small text-muted">'.e($l->type_label).'</div>'.($url ? '<a href="'.$url.'">'.$label.'</a>' : $label);
                })
                ->addColumn('changes', fn (ActivityLog $l) => view('audit._changes', ['log' => $l])->render())
                ->filterColumn('user', fn ($q, $kw) => $q->whereIn('user_id', User::withTrashed()->where('name', 'like', "%{$kw}%")->select('id')))
                ->filterColumn('record', fn ($q, $kw) => $q->where('subject_label', 'like', "%{$kw}%"))
                ->orderColumn('user', fn ($q, $dir) => $q->orderBy('user_id', $dir))
                ->orderColumn('record', fn ($q, $dir) => $q->orderBy('subject_label', $dir))
                ->rawColumns(['created_at', 'event', 'record', 'changes'])
                ->toJson();
        }

        return view('audit.index', [
            'types' => ActivityLog::TYPES,
            'events' => array_keys(ActivityLog::EVENTS),
            'users' => User::withTrashed()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
