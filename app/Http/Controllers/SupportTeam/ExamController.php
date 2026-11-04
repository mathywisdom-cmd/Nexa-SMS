<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\Exam\ExamCreate;
use App\Http\Requests\Exam\ExamUpdate;
use App\Repositories\ExamRepo;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;

class ExamController extends Controller
{
    protected $exam;
    public function __construct(ExamRepo $exam)
    {
        $this->middleware('permission:exams.view', ['only' => ['index']]);
        $this->middleware('permission:exams.manage', ['only' => ['store', 'edit', 'update', 'destroy']]);

        $this->exam = $exam;
    }

    public function index()
    {
        $d['exams'] = $this->exam->all();
        return view('pages.support_team.exams.index', $d);
    }

    public function store(ExamCreate $req)
    {
        $data = $req->only(['name', 'term']);
        $data['year'] = Qs::getSetting('current_session');

        $exam = $this->exam->create($data);
        app(AuditLogger::class)->record('exam.created', $exam, 'Exam created.', ['name' => $exam->name, 'term' => $exam->term, 'year' => $exam->year]);
        return back()->with('flash_success', __('msg.store_ok'));
    }

    public function edit($id)
    {
        $d['ex'] = $this->exam->find($id);
        abort_if(!$d['ex'], 404);
        return view('pages.support_team.exams.edit', $d);
    }

    public function update(ExamUpdate $req, $id)
    {
        $data = $req->only(['name', 'term']);

        $exam = $this->exam->find($id);
        abort_if(!$exam, 404);
        $before = $exam->only(['name', 'term', 'year']);
        $this->exam->update($id, $data);
        app(AuditLogger::class)->record('exam.updated', $exam, 'Exam updated.', ['before' => $before, 'after' => array_merge($before, $data)]);
        return back()->with('flash_success', __('msg.update_ok'));
    }

    public function destroy($id)
    {
        $exam = $this->exam->find($id);
        abort_if(!$exam, 404);
        app(AuditLogger::class)->record('exam.deleted', $exam, 'Exam deleted.', $exam->only(['name', 'term', 'year']));
        $this->exam->delete($id);
        return back()->with('flash_success', __('msg.del_ok'));
    }
}
