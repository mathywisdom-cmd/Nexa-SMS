<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\UserRequest;
use App\Repositories\LocationRepo;
use App\Repositories\MyClassRepo;
use App\Repositories\UserRepo;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\AuditLogger;
use App\Models\Lga;
use App\Models\State;
use App\Models\Nationality;


class UserController extends Controller
{
    protected $user, $loc, $my_class;

    public function __construct(UserRepo $user, LocationRepo $loc, MyClassRepo $my_class)
    {
        $this->middleware('teamSA', ['only' => ['index', 'store', 'edit', 'update'] ]);
        $this->middleware('teamSA', ['only' => ['reset_pass','destroy'] ]);

        $this->user = $user;
        $this->loc = $loc;
        $this->my_class = $my_class;
    }

    public function index()
    {
        $ut = $this->user->getAllTypes();
        $ut2 = $ut->where('level', '>', 2);

        $d['user_types'] = Qs::userIsAdmin() ? $ut2 : $ut;
        $schoolCreatable = config('user_types.school_creatable', []);
        $d['creatable_user_types'] = $d['user_types']
            ->whereIn('title', $schoolCreatable)
            ->sortBy(function ($type) use ($schoolCreatable) {
                return array_search($type->title, $schoolCreatable, true);
            })->values();
        $d['states'] = $this->loc->getUgandaStates();
        $d['users'] = $this->user->getPTAUsers();
        $d['nationals'] = $this->loc->getAllNationals();
        $d['default_nationality_id'] = Nationality::where('name', 'Uganda')->value('id');
        $d['selected_state_id'] = old('state_id');
        $d['selected_lga_id'] = old('lga_id');
        $d['lgas'] = $d['selected_state_id'] ? $this->loc->getLGAs($d['selected_state_id']) : collect();
        $d['blood_groups'] = $this->user->getBloodGroups();
        return view('pages.support_team.users.index', $d);
    }

    public function edit($id)
    {
        $id = Qs::decodeHash($id);
        $d['user'] = $this->user->find($id);
        abort_if(!$d['user'], 404);
        $selectedStateId = old('state_id', $d['user']->state_id);
        $selectedLgaId = old('lga_id', $d['user']->lga_id);
        $d['states'] = $this->loc->getUgandaStates();
        $existingState = State::find($selectedStateId);
        if ($existingState && !$d['states']->contains('id', $existingState->id)) {
            $d['states']->push($existingState);
        }
        $d['selected_state_id'] = $selectedStateId;
        $d['selected_lga_id'] = $selectedLgaId;
        $d['lgas'] = $selectedStateId ? $this->loc->getLGAs($selectedStateId) : collect();
        $existingLga = Lga::find($selectedLgaId);
        if ($existingLga && !$d['lgas']->contains('id', $existingLga->id)) {
            $d['lgas']->push($existingLga);
        }
        $d['users'] = $this->user->getPTAUsers();
        $d['blood_groups'] = $this->user->getBloodGroups();
        $d['nationals'] = $this->loc->getAllNationals();
        return view('pages.support_team.users.edit', $d);
    }

    public function reset_pass($id)
    {
        $id = Qs::decodeHash($id);
        $data['password'] = Hash::make('user');
        $this->user->update($id, $data);
        return back()->with('flash_success', __('msg.pu_reset'));
    }

    public function store(UserRequest $req)
    {
        $user_type = $this->user->findType($req->user_type)->title;
        abort_if($user_type === 'super_admin', 403);

        $data = $req->except(Qs::getStaffRecord());
        unset($data['school_id']);
        $data['name'] = ucwords($req->name);
        $data['user_type'] = $user_type;
        $data['photo'] = Qs::getDefaultUserImage();
        $data['code'] = strtoupper(Str::random(10));

        $user_is_staff = in_array($user_type, Qs::getStaff());
        $user_is_teamSA = in_array($user_type, Qs::getTeamSA());

        $staff_id = Qs::getAppCode().'/STAFF/'.date('Y/m', strtotime($req->emp_date)).'/'.mt_rand(1000, 9999);
        $data['username'] = $uname = ($user_is_teamSA) ? $req->username : $staff_id;

        $pass = $req->password ?: $user_type;
        $data['password'] = Hash::make($pass);

        if($req->hasFile('photo')) {
            $photo = $req->file('photo');
            $f = Qs::getFileMetaData($photo);
            $f['name'] = 'photo.' . $f['ext'];
            $f['path'] = $photo->storeAs(Qs::getUploadPath($user_type).$data['code'], $f['name']);
            $data['photo'] = asset('storage/' . $f['path']);
        }

        /* Ensure that both username and Email are not blank*/
        if(!$uname && !$req->email){
            return back()->with('pop_error', __('msg.user_invalid'));
        }

        $user = $this->user->create($data); // Create User
        app(AuditLogger::class)->record('user.created', $user, 'School user created.', ['user_type' => $user_type]);

        /* CREATE STAFF RECORD */
        if($user_is_staff){
            $d2 = $req->only(Qs::getStaffRecord());
            $d2['user_id'] = $user->id;
            $d2['code'] = $staff_id;
            $this->user->createStaffRecord($d2);
        }

        return Qs::jsonStoreOk();
    }

    public function update(UserRequest $req, $id)
    {
        $id = Qs::decodeHash($id);

        $user = $this->user->find($id);
        abort_if(!$user, 404);
        $before = $user->only(['name', 'user_type']);

        $user_type = $user->user_type;
        $user_is_staff = in_array($user_type, Qs::getStaff());
        $user_is_teamSA = in_array($user_type, Qs::getTeamSA());

        $data = $req->except(Qs::getStaffRecord());
        unset($data['school_id']);
        $data['name'] = ucwords($req->name);
        $data['user_type'] = $user_type;

        if($user_is_staff && !$user_is_teamSA){
            $data['username'] = Qs::getAppCode().'/STAFF/'.date('Y/m', strtotime($req->emp_date)).'/'.mt_rand(1000, 9999);
        }
        else {
            $data['username'] = $user->username;
        }

        if($req->hasFile('photo')) {
            $photo = $req->file('photo');
            $f = Qs::getFileMetaData($photo);
            $f['name'] = 'photo.' . $f['ext'];
            $f['path'] = $photo->storeAs(Qs::getUploadPath($user_type).$user->code, $f['name']);
            $data['photo'] = asset('storage/' . $f['path']);
        }

        $this->user->update($id, $data);   /* UPDATE USER RECORD */
        app(AuditLogger::class)->record('user.updated', $user, 'School user updated.', [
            'before' => $before, 'after' => ['name' => $data['name'], 'user_type' => $data['user_type']],
        ]);

        /* UPDATE STAFF RECORD */
        if($user_is_staff){
            $d2 = $req->only(Qs::getStaffRecord());
            $d2['code'] = $data['username'];
            $this->user->updateStaffRecord(['user_id' => $id], $d2);
        }

        return Qs::jsonUpdateOk();
    }

    public function show($user_id)
    {
        $user_id = Qs::decodeHash($user_id);
        if(!$user_id){return back();}

        $data['user'] = $this->user->find($user_id);
        abort_if(!$data['user'], 404);

        /* Prevent Other Students from viewing Profile of others*/
        if(Auth::user()->id != $user_id && !Qs::userIsTeamSAT() && !Qs::userIsMyChild($user_id, Auth::user()->id)){
            return redirect(route('dashboard'))->with('pop_error', __('msg.denied'));
        }
        if (Qs::userIsTeacher() && $data['user']->user_type === 'student') {
            abort_unless($data['user']->student_record && \App\Models\Section::where('id', $data['user']->student_record->section_id)->where('teacher_id', Auth::id())->exists(), 404);
        }

        return view('pages.support_team.users.show', $data);
    }

    public function destroy($id)
    {
        $id = Qs::decodeHash($id);

        $user = $this->user->find($id);
        abort_if(!$user, 404);
        app(AuditLogger::class)->record('user.deleted', $user, 'School user deleted.', ['user_type' => $user->user_type, 'name' => $user->name]);

        if($user->user_type == 'teacher' && $this->userTeachesSubject($user)) {
            return back()->with('pop_error', __('msg.del_teacher'));
        }

        $path = Qs::getUploadPath($user->user_type).$user->code;
        Storage::exists($path) ? Storage::deleteDirectory($path) : true;
        $this->user->delete($user->id);

        return back()->with('flash_success', __('msg.del_ok'));
    }

    protected function userTeachesSubject($user)
    {
        $subjects = $this->my_class->findSubjectByTeacher($user->id);
        return ($subjects->count() > 0) ? true : false;
    }

}
