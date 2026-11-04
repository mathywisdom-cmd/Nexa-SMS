<?php

namespace App\Repositories;

use App\Models\Nationality;
use App\Models\State;
use App\Models\Lga;

class LocationRepo
{
    public function getStates()
    {
        return State::all();
    }

    public function getUgandaStates()
    {
        $regions = array_keys(config('locations.uganda', []));
        $states = State::whereIn('name', $regions)->get()->keyBy('name');

        return collect($regions)->map(function ($region) use ($states) {
            return $states->get($region);
        })->filter()->values();
    }

    public function getAllStates()
    {
        return State::orderBy('name', 'asc')->get();
    }

    public function getAllNationals()
    {
        return Nationality::orderBy('name', 'asc')->get();
    }

    public function getLGAs($state_id)
    {
        return Lga::where('state_id', $state_id)->orderBy('name', 'asc')->get();
    }

}
