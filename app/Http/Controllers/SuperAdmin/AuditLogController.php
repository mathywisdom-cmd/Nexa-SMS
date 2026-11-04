<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;

class AuditLogController extends Controller
{
    public function index()
    {
        $query = AuditLog::withoutGlobalScope('school')->with([
            'school', 'actor' => function ($query) { $query->withoutGlobalScope('school'); },
        ]);
        if (request('school_id')) {
            $query->where('school_id', request('school_id'));
        }
        if (request('action')) {
            $query->where('action', 'like', '%'.request('action').'%');
        }

        $logs = $query->latest()->paginate(50)->appends(request()->query());
        $schools = School::orderBy('name')->get();
        return view('pages.super_admin.audit_logs.index', compact('logs', 'schools'));
    }
}
