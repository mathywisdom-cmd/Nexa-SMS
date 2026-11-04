<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Requests\SettingUpdate;
use App\Repositories\MyClassRepo;
use App\Repositories\SettingRepo;
use App\Tenancy\TenantContext;
use App\Services\AuditLogger;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SettingController extends Controller
{
    protected $setting, $my_class;

    public function __construct(SettingRepo $setting, MyClassRepo $my_class)
    {
        $this->setting = $setting;
        $this->my_class = $my_class;
    }

    public function index()
    {
         $this->requireSchoolContext();
         $s = $this->setting->all();
         $d['class_types'] = $this->my_class->getTypes();
         $d['s'] = $s->flatMap(function($s){
            return [$s->type => $s->description];
        });
        return view('pages.super_admin.settings', $d);
    }

    public function update(SettingUpdate $req)
    {
        $this->requireSchoolContext();
        $sets = $req->except('_token', '_method', 'logo');
        $changedKeys = array_keys($sets);
        $sets['lock_exam'] = $sets['lock_exam'] == 1 ? 1 : 0;
        $keys = array_keys($sets);
        $values = array_values($sets);
        for($i=0; $i<count($sets); $i++){
            $this->setting->update($keys[$i], $values[$i]);
        }

        if($req->hasFile('logo')) {
            $logo = $req->file('logo');
            $f = Qs::getFileMetaData($logo);
            $f['name'] = 'logo.' . $f['ext'];
            $f['path'] = $logo->storeAs(Qs::getPublicUploadPath(), $f['name']);
            $logo_path = asset('storage/' . $f['path']);
            $this->setting->update('logo', $logo_path);
            $changedKeys[] = 'logo';
        }

        app(AuditLogger::class)->record('school.settings_updated', null, 'School settings updated.', [
            'changed_keys' => array_values(array_unique($changedKeys)),
        ]);

        return back()->with('flash_success', __('msg.update_ok'));

    }

    protected function requireSchoolContext()
    {
        if (!app(TenantContext::class)->schoolId()) {
            throw new AccessDeniedHttpException('School settings can only be managed from a school account.');
        }
    }
}
