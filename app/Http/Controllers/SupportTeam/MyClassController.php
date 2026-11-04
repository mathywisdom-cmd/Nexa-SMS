<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\MyClass\ClassCreate;
use App\Http\Requests\MyClass\ClassUpdate;
use App\Repositories\MyClassRepo;
use App\Repositories\UserRepo;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;

class MyClassController extends Controller
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
        $d['class_types'] = $this->my_class->getTypes();

        return view('pages.support_team.classes.index', $d);
    }

    public function store(ClassCreate $req)
    {
        $data = $req->all();
        $mc = $this->my_class->create($data);
        app(AuditLogger::class)->record('class.created', $mc, 'Class created.', ['name' => $mc->name]);

        // Create Default Section
        $s =['my_class_id' => $mc->id,
            'name' => 'A',
            'active' => 1,
            'teacher_id' => NULL,
        ];

        $this->my_class->createSection($s);

        return Qs::jsonStoreOk();
    }

    public function edit($id)
    {
        $d['c'] = $c = $this->my_class->find($id);

        return is_null($c) ? Qs::goWithDanger('classes.index') : view('pages.support_team.classes.edit', $d) ;
    }

    public function update(ClassUpdate $req, $id)
    {
        $data = $req->only(['name']);
        $class = $this->my_class->find($id);
        abort_if(!$class, 404);
        $before = $class->only(['name']);
        $this->my_class->update($id, $data);
        app(AuditLogger::class)->record('class.updated', $class, 'Class updated.', ['before' => $before, 'after' => $data]);

        return Qs::jsonUpdateOk();
    }

    public function destroy($id)
    {
        $class = $this->my_class->find($id);
        abort_if(!$class, 404);
        app(AuditLogger::class)->record('class.deleted', $class, 'Class deleted.', ['name' => $class->name]);
        $this->my_class->delete($id);
        return back()->with('flash_success', __('msg.del_ok'));
    }

}
