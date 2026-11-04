<?php

namespace App\Http\Controllers;

use App\Helpers\Qs;
use App\Repositories\UserRepo;
use App\Models\AttendanceRecord;
use App\Models\Exam;
use App\Models\MyClass;
use App\Models\PaymentRecord;
use App\Models\Receipt;
use App\Models\AuditLog;

class HomeController extends Controller
{
    protected $user;
    public function __construct(UserRepo $user)
    {
        $this->user = $user;
    }


    public function index()
    {
        return redirect()->route('dashboard');
    }

    public function privacy_policy()
    {
        return view('pages.other.privacy_policy');
    }

    public function terms_of_use()
    {
        return view('pages.other.terms_of_use');
    }

    public function dashboard()
    {
        if (Qs::userIsSuperAdmin()) {
            return redirect()->route('owner.dashboard');
        }

        $d=[];
        if(Qs::userIsTeamSAT()){
            $d['users'] = $this->user->getAll();
        }

        if (Qs::userIsAdmin()) {
            $today = date('Y-m-d');
            $todayAttendance = AttendanceRecord::whereHas('session', function ($query) use ($today) {
                $query->whereDate('attendance_date', $today);
            });
            $d['total_classes'] = MyClass::count();
            $d['attendance_today'] = [
                'present' => (clone $todayAttendance)->where('status', 'present')->count(),
                'absent' => (clone $todayAttendance)->where('status', 'absent')->count(),
                'late' => (clone $todayAttendance)->where('status', 'late')->count(),
            ];
            $attendanceCount = (clone $todayAttendance)->count();
            $d['attendance_percentage'] = $attendanceCount ? round((($d['attendance_today']['present'] + $d['attendance_today']['late']) / $attendanceCount) * 100, 1) : null;
            $d['recent_activity'] = AuditLog::latest()->take(5)->get();
            $d['fee_collection_today'] = Receipt::whereDate('created_at', $today)->sum('amt_paid');
            $d['outstanding_fees'] = PaymentRecord::sum('balance');
            $d['current_session_exams'] = Exam::where('year', Qs::getCurrentSession())->count();
            $d['recent_payments'] = PaymentRecord::with(['student', 'payment'])->where('amt_paid', '>', 0)->latest()->take(5)->get();
        }

        return view('pages.support_team.dashboard', $d);
    }
}
