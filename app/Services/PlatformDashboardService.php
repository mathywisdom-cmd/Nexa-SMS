<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\School;
use Illuminate\Support\Facades\DB;

class PlatformDashboardService
{
    protected $periods = [
        'today' => 'Today',
        '7_days' => 'Last 7 days',
        '30_days' => 'Last 30 days',
        'year' => 'This year',
    ];

    public function data($period = null)
    {
        $period = isset($this->periods[$period]) ? $period : '30_days';
        $now = now();
        $start = $this->periodStart($period, $now->copy());
        $schoolStats = $this->schoolStatsQuery();

        $statusCounts = School::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $userTotals = DB::table('users')
            ->whereNotNull('school_id')
            ->selectRaw(
                'SUM(CASE WHEN user_type = ? THEN 1 ELSE 0 END) as students, '
                .'SUM(CASE WHEN user_type = ? THEN 1 ELSE 0 END) as teachers, '
                .'SUM(CASE WHEN user_type = ? THEN 1 ELSE 0 END) as parents, '
                .'SUM(CASE WHEN user_type IN (?, ?, ?, ?, ?, ?, ?, ?) THEN 1 ELSE 0 END) as staff',
                ['student', 'teacher', 'parent', 'admin', 'principal', 'head_teacher', 'teacher', 'accountant', 'bursar', 'librarian', 'staff']
            )->first();

        $recentSchools = (clone $schoolStats)
            ->where('schools.created_at', '>=', $start)
            ->orderByDesc('schools.created_at')
            ->limit(10)
            ->get();

        $adminNames = collect();
        if ($recentSchools->isNotEmpty()) {
            $adminNames = DB::table('users')
                ->whereIn('school_id', $recentSchools->pluck('id'))
                ->where('user_type', 'admin')
                ->orderBy('id')
                ->get(['school_id', 'name', 'email'])
                ->groupBy('school_id');
        }
        foreach ($recentSchools as $school) {
            $admin = optional($adminNames->get($school->id))->first();
            $school->administrator_name = $admin ? $admin->name : null;
            $school->administrator_email = $admin ? $admin->email : null;
        }

        $topSchools = (clone $schoolStats)
            ->orderByDesc('students_count')
            ->orderBy('schools.name')
            ->limit(5)
            ->get();

        $suspendedSchools = (int) ($statusCounts->get('suspended') ?: 0);
        $inactiveSchools = (int) ($statusCounts->get('inactive') ?: 0);
        $schoolsWithoutAdmin = School::query()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.school_id', 'schools.id')
                    ->where('users.user_type', 'admin');
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'code']);
        $schoolsWithoutAdminCount = School::query()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.school_id', 'schools.id')
                    ->where('users.user_type', 'admin');
            })->count();

        $recentlyActivated = AuditLog::withoutGlobalScope('school')
            ->where('action', 'school.updated')
            ->where('created_at', '>=', $start)
            ->where('properties->after->status', 'active')
            ->where('properties->before->status', '!=', 'active')
            ->distinct('school_id')->count('school_id');
        $recentlySuspended = AuditLog::withoutGlobalScope('school')
            ->where('action', 'school.updated')
            ->where('created_at', '>=', $start)
            ->where('properties->after->status', 'suspended')
            ->where('properties->before->status', '!=', 'suspended')
            ->distinct('school_id')->count('school_id');

        $ownerIds = DB::table('users')->where('user_type', 'super_admin')->pluck('id');
        $activityQuery = AuditLog::withoutGlobalScope('school')
            ->with([
                'school',
                'actor' => function ($query) {
                    $query->withoutGlobalScope('school')->select('users.id', 'users.name', 'users.user_type');
                },
            ])
            ->where('created_at', '>=', $start)
            ->where(function ($query) use ($ownerIds) {
                $query->whereIn('action', ['school.created', 'school.updated']);
                if ($ownerIds->isNotEmpty()) {
                    $query->orWhereIn('actor_id', $ownerIds);
                }
            });
        $recentActivity = $activityQuery->latest()->limit(10)->get();

        $recentActiveSchools = DB::table('audit_logs')
            ->select('school_id')
            ->selectRaw('MAX(created_at) as last_activity_at')
            ->whereNotNull('school_id')
            ->where('created_at', '>=', $start)
            ->groupBy('school_id')
            ->orderByDesc('last_activity_at')
            ->limit(5)
            ->get();
        $recentActiveSchoolStats = collect();
        if ($recentActiveSchools->isNotEmpty()) {
            $recentActiveSchoolStats = (clone $schoolStats)
                ->whereIn('schools.id', $recentActiveSchools->pluck('school_id'))
                ->get()
                ->keyBy('id');
            foreach ($recentActiveSchools as $activity) {
                if ($recentActiveSchoolStats->has($activity->school_id)) {
                    $recentActiveSchoolStats->get($activity->school_id)->last_activity_at = $activity->last_activity_at;
                }
            }
        }

        $alerts = [];
        if ($suspendedSchools > 0) {
            $alerts[] = ['label' => 'Suspended schools', 'count' => $suspendedSchools, 'status' => 'danger'];
        }
        if ($inactiveSchools > 0) {
            $alerts[] = ['label' => 'Inactive schools', 'count' => $inactiveSchools, 'status' => 'warning'];
        }
        if ($schoolsWithoutAdminCount > 0) {
            $alerts[] = ['label' => 'Schools without an administrator', 'count' => $schoolsWithoutAdminCount, 'status' => 'warning', 'schools' => $schoolsWithoutAdmin];
        }

        try {
            DB::connection()->getPdo();
            $databaseAvailable = true;
        } catch (\Throwable $exception) {
            $databaseAvailable = false;
        }

        return [
            'period' => $period,
            'periods' => $this->periods,
            'periodLabel' => $this->periods[$period],
            'periodStart' => $start,
            'totalSchools' => (int) $statusCounts->sum(),
            'activeSchools' => (int) ($statusCounts->get('active') ?: 0),
            'inactiveSchools' => $inactiveSchools,
            'suspendedSchools' => $suspendedSchools,
            'trialSchools' => (int) ($statusCounts->get('trial') ?: 0),
            'totalStudents' => (int) ($userTotals->students ?: 0),
            'totalTeachers' => (int) ($userTotals->teachers ?: 0),
            'totalStaff' => (int) ($userTotals->staff ?: 0),
            'totalParents' => (int) ($userTotals->parents ?: 0),
            'recentRegistrationsCount' => (clone $schoolStats)->where('schools.created_at', '>=', $start)->count('schools.id'),
            'recentlyActivated' => $recentlyActivated,
            'recentlySuspended' => $recentlySuspended,
            'recentSchools' => $recentSchools,
            'topSchools' => $topSchools,
            'recentActiveSchools' => $recentActiveSchoolStats->values(),
            'recentActivity' => $recentActivity,
            'alerts' => $alerts,
            'statusTotal' => (int) $statusCounts->sum(),
            'statusBreakdown' => [
                'active' => (int) ($statusCounts->get('active') ?: 0),
                'inactive' => $inactiveSchools,
                'suspended' => $suspendedSchools,
            ],
            'databaseAvailable' => $databaseAvailable,
            'storageAvailable' => is_dir(storage_path()) && is_writable(storage_path()),
        ];
    }

    protected function schoolStatsQuery()
    {
        $counts = DB::table('users')
            ->select('school_id')
            ->whereNotNull('school_id')
            ->selectRaw(
                'SUM(CASE WHEN user_type = ? THEN 1 ELSE 0 END) as students_count, '
                .'SUM(CASE WHEN user_type = ? THEN 1 ELSE 0 END) as teachers_count, '
                .'SUM(CASE WHEN user_type = ? THEN 1 ELSE 0 END) as parents_count, '
                .'SUM(CASE WHEN user_type = ? THEN 1 ELSE 0 END) as admins_count',
                ['student', 'teacher', 'parent', 'admin']
            )
            ->groupBy('school_id');

        return School::query()
            ->leftJoinSub($counts, 'school_user_counts', function ($join) {
                $join->on('school_user_counts.school_id', '=', 'schools.id');
            })
            ->select('schools.*')
            ->selectRaw(
                'COALESCE(school_user_counts.students_count, 0) as students_count, '
                .'COALESCE(school_user_counts.teachers_count, 0) as teachers_count, '
                .'COALESCE(school_user_counts.parents_count, 0) as parents_count, '
                .'COALESCE(school_user_counts.admins_count, 0) as admins_count'
            );
    }

    protected function periodStart($period, $now)
    {
        if ($period === 'today') {
            return $now->startOfDay();
        }
        if ($period === '7_days') {
            return $now->subDays(6)->startOfDay();
        }
        if ($period === 'year') {
            return $now->startOfYear();
        }
        return $now->subDays(29)->startOfDay();
    }
}
