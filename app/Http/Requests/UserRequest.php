<?php

namespace App\Http\Requests;

use App\Helpers\Qs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
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
        $store =  [
            'name' => 'required|string|min:6|max:150',
            'password' => 'nullable|string|min:3|max:50',
            'user_type' => [
                'required', 'integer',
                Rule::exists('user_types', 'id')->where(function ($query) {
                    $query->whereIn('title', config('user_types.school_creatable', []));
                }),
            ],
            'gender' => 'required|string',
            'phone' => 'sometimes|nullable|string|min:6|max:20',
            'email' => 'sometimes|nullable|email|max:100|unique:users',
            'username' => 'sometimes|nullable|alpha_dash|min:8|max:100|unique:users',
            'photo' => 'sometimes|nullable|image|mimes:jpeg,gif,png,jpg|max:2048',
            'address' => 'required|string|min:6|max:120',
            'state_id' => [
                'required', 'integer',
                Rule::exists('states', 'id')->where(function ($query) {
                    $query->whereIn('name', array_keys(config('locations.uganda', [])));
                }),
            ],
            'lga_id' => [
                'required', 'integer',
                Rule::exists('lgas', 'id')->where(function ($query) {
                    $query->where('state_id', $this->input('state_id'));
                }),
            ],
            'nal_id' => ['required', 'integer', Rule::exists('nationalities', 'id')],
        ];
        $update =  [
            'name' => 'required|string|min:6|max:150',
            'gender' => 'required|string',
            'phone' => 'sometimes|nullable|string|min:6|max:20',
            'phone2' => 'sometimes|nullable|string|min:6|max:20',
            'email' => 'sometimes|nullable|email|max:100|unique:users,email,'.$this->user,
            'photo' => 'sometimes|nullable|image|mimes:jpeg,gif,png,jpg|max:2048',
            'address' => 'required|string|min:6|max:120',
            'state_id' => ['required', 'integer', Rule::exists('states', 'id')],
            'lga_id' => [
                'required', 'integer',
                Rule::exists('lgas', 'id')->where(function ($query) {
                    $query->where('state_id', $this->input('state_id'));
                }),
            ],
            'nal_id' => ['required', 'integer', Rule::exists('nationalities', 'id')],
        ];
        return ($this->method() === 'POST') ? $store : $update;
    }

    public function attributes()
    {
        return  [
            'nal_id' => 'Nationality',
            'state_id' => 'Region',
            'lga_id' => 'District',
            'user_type' => 'User',
            'phone2' => 'Telephone',
        ];
    }

    protected function getValidatorInstance()
    {
        if($this->method() === 'POST'){
            $input = $this->all();

            $input['user_type'] = Qs::decodeHash($input['user_type']);

            $this->getInputSource()->replace($input);

        }

        if($this->method() === 'PUT'){
            $this->user = Qs::decodeHash($this->user);
        }

        return parent::getValidatorInstance();

    }
}
