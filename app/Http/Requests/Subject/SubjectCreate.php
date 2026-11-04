<?php

namespace App\Http\Requests\Subject;

use App\Helpers\Qs;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubjectCreate extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $schoolId = app(TenantContext::class)->schoolId();

        return [
            'name' => 'required|string|min:3',
            'my_class_id' => ['required', 'integer', Rule::exists('my_classes', 'id')->where('school_id', $schoolId)],
            'teacher_id' => ['required', 'integer', Rule::exists('users', 'id')->where('school_id', $schoolId)->where('user_type', 'teacher')],
            'slug' => 'nullable|string|min:3',
        ];
    }

    public function attributes()
    {
        return  [
            'my_class_id' => 'Class',
            'teacher_id' => 'Teacher',
            'slug' => 'Short Name',
        ];
    }

    protected function getValidatorInstance()
    {
        $input = $this->all();

        if (array_key_exists('teacher_id', $input)) {
            $teacherId = $input['teacher_id'];
            $input['teacher_id'] = $teacherId === null || $teacherId === ''
                ? null
                : (is_scalar($teacherId) ? Qs::decodeHash((string) $teacherId) : $teacherId);
        }

        $this->getInputSource()->replace($input);

        return parent::getValidatorInstance();
    }
}
