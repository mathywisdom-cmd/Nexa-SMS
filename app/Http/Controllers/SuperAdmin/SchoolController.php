<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\AuditLogger;
use App\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SchoolController extends Controller
{
    public function index()
    {
        $schools = School::withCount(['users as students_count' => function ($query) {
            $query->withoutGlobalScope('school');
            $query->where('user_type', 'student');
        }, 'users as teachers_count' => function ($query) {
            $query->withoutGlobalScope('school');
            $query->where('user_type', 'teacher');
        }])->orderBy('name')->paginate(20);

        return view('pages.super_admin.schools.index', compact('schools'));
    }

    public function create()
    {
        return view('pages.super_admin.schools.create');
    }

    public function store()
    {
        $data = request()->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|alpha_dash|unique:schools,code',
            'registration_number' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'timezone' => 'required|string|max:64',
            'currency' => 'required|string|size:3',
            'status' => 'required|in:active,inactive,suspended,trial',
            'admin_name' => 'required|string|max:255',
            'admin_email' => ['required', 'email', 'max:100', 'unique:users,email', 'unique:users,username'],
            'admin_password' => 'required|string|min:8|confirmed',
        ]);

        DB::transaction(function () use ($data) {
            $school = School::create(collect($data)->only([
                'name', 'code', 'registration_number', 'email', 'phone', 'address', 'timezone', 'currency', 'status',
            ])->all());

            User::create([
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'username' => $data['admin_email'],
                'code' => Str::random(32),
                'user_type' => 'admin',
                'school_id' => $school->id,
                'password' => Hash::make($data['admin_password']),
            ]);

            app(AuditLogger::class)->record('school.created', $school, 'School and first administrator created.', [
                'code' => $school->code, 'status' => $school->status,
            ], $school->id);
        });

        return redirect()->route('owner.schools.index')->with('flash_success', 'School and administrator created.');
    }

    public function edit(School $school)
    {
        $school->setAttribute('users_count', $school->users()->withoutGlobalScope('school')->count());
        return view('pages.super_admin.schools.edit', compact('school'));
    }

    public function update(School $school)
    {
        $data = request()->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('schools', 'code')->ignore($school->id)],
            'registration_number' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'timezone' => 'required|string|max:64',
            'currency' => 'required|string|size:3',
            'status' => 'required|in:active,inactive,suspended,trial',
        ]);

        $before = $school->only(['name', 'code', 'status']);
        $school->update($data);
        app(AuditLogger::class)->record('school.updated', $school, 'School details updated.', [
            'before' => $before, 'after' => $school->only(['name', 'code', 'status']),
        ], $school->id);
        return redirect()->route('owner.schools.index')->with('flash_success', 'School updated.');
    }
}
