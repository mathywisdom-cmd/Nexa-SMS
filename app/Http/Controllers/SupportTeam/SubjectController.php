<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subject\SubjectCreate;
use App\Http\Requests\Subject\SubjectUpdate;
use App\Repositories\MyClassRepo;
use App\Repositories\UserRepo;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;

class SubjectController extends Controller
{
    protected $my_class;
    protected $user;

    public function __construct(MyClassRepo $my_class, UserRepo $user)
    {
        $this->middleware('permission:subjects.view', ['only' => ['index', 'show']]);
        $this->middleware('permission:subjects.manage', ['only' => ['create', 'store', 'edit', 'update', 'destroy']]);
        $this->my_class = $my_class;
        $this->user = $user;
    }

    public function index()
    {
        $subjects = $this->my_class->getAllSubjects();
        if (Qs::userIsTeacher()) {
            $subjects = $subjects->where('teacher_id', Auth::id());
        }

        return view('pages.support_team.subjects.index', [
            'my_classes' => $this->my_class->all(),
            'teachers' => $this->user->getUserByType('teacher'),
            'subjects' => $subjects,
            'canManageSubjects' => app(\App\Services\PermissionService::class)->userCan('subjects.manage'),
        ]);
    }

    public function create()
    {
        return redirect()->route('subjects.index');
    }

    public function store(SubjectCreate $request)
    {
        $data = $request->only(['name', 'slug', 'my_class_id', 'teacher_id']);
        $subject = $this->my_class->createSubject($data);
        app(AuditLogger::class)->record('subject.created', $subject, 'Subject created.', [
            'name' => $subject->name, 'class_id' => $subject->my_class_id,
        ]);
        return Qs::jsonStoreOk();
    }

    public function show($id)
    {
        $subject = $this->my_class->findSubject($id);
        abort_if(!$subject, 404);
        return app(\App\Services\PermissionService::class)->userCan('subjects.manage')
            ? redirect()->route('subjects.edit', $subject->id)
            : redirect()->route('subjects.index');
    }

    public function edit($id)
    {
        $subject = $this->my_class->findSubject($id);
        if (!$subject) return Qs::goWithDanger('subjects.index');
        return view('pages.support_team.subjects.edit', [
            's' => $subject, 'my_classes' => $this->my_class->all(), 'teachers' => $this->user->getUserByType('teacher'),
        ]);
    }

    public function update(SubjectUpdate $request, $id)
    {
        $subject = $this->my_class->findSubject($id);
        abort_if(!$subject, 404);
        $data = $request->only(['name', 'slug', 'my_class_id', 'teacher_id']);
        $before = $subject->only(['name', 'slug', 'my_class_id', 'teacher_id']);
        $this->my_class->updateSubject($id, $data);
        app(AuditLogger::class)->record('subject.updated', $subject, 'Subject updated.', ['before' => $before, 'after' => $data]);
        return Qs::jsonUpdateOk();
    }

    public function destroy($id)
    {
        $subject = $this->my_class->findSubject($id);
        abort_if(!$subject, 404);
        app(AuditLogger::class)->record('subject.deleted', $subject, 'Subject deleted.', [
            'name' => $subject->name, 'class_id' => $subject->my_class_id,
        ]);
        $this->my_class->deleteSubject($id);
        return Qs::deleteOk('subjects.index');
    }
}
