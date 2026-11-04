<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\Section\SectionCreate;
use App\Http\Requests\Section\SectionUpdate;
use App\Repositories\MyClassRepo;
use App\Http\Controllers\Controller;
use App\Repositories\UserRepo;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;

class SectionController extends Controller
{
    protected $my_class, $user;

    public function __construct(MyClassRepo $my_class, UserRepo $user)
    {
        $this->middleware('permission:classes.view', ['only' => ['index', 'show']]);
        $this->middleware('permission:classes.manage', ['only' => ['create', 'store', 'edit', 'update', 'destroy']]);

        $this->my_class = $my_class;
        $this->user = $user;
    }

    public function index()
    {
        $d['my_classes'] = $this->my_class->all();
        $d['sections'] = $this->my_class->getAllSections();
        if (Qs::userIsTeacher()) {
            $d['sections'] = $d['sections']->where('teacher_id', Auth::id());
        }
        $d['teachers'] = $this->user->getUserByType('teacher');

        return view('pages.support_team.sections.index', $d);
    }

    public function store(SectionCreate $req)
    {
        $data = $req->all();
        $section = $this->my_class->createSection($data);
        app(AuditLogger::class)->record('section.created', $section, 'Section created.', ['name' => $section->name, 'class_id' => $section->my_class_id]);

        return Qs::jsonStoreOk();
    }

    public function edit($id)
    {
        $d['s'] = $s = $this->my_class->findSection($id);
        $d['teachers'] = $this->user->getUserByType('teacher');

        return is_null($s) ? Qs::goWithDanger('sections.index') :view('pages.support_team.sections.edit', $d);
    }

    public function update(SectionUpdate $req, $id)
    {
        $data = $req->only(['name', 'teacher_id']);
        $section = $this->my_class->findSection($id);
        abort_if(!$section, 404);
        $before = $section->only(['name', 'teacher_id']);
        $this->my_class->updateSection($id, $data);
        app(AuditLogger::class)->record('section.updated', $section, 'Section updated.', ['before' => $before, 'after' => $data]);

        return Qs::jsonUpdateOk();
    }

    public function destroy($id)
    {
        if($this->my_class->isActiveSection($id)){
            return back()->with('pop_warning', 'Every class must have a default section, You Cannot Delete It');
        }

        $section = $this->my_class->findSection($id);
        abort_if(!$section, 404);
        app(AuditLogger::class)->record('section.deleted', $section, 'Section deleted.', ['name' => $section->name, 'class_id' => $section->my_class_id]);
        $this->my_class->deleteSection($id);
        return back()->with('flash_success', __('msg.del_ok'));
    }

}
