<?php

namespace App\Tenancy;

/** Holds the active school for the current request. */
class TenantContext
{
    protected $schoolId;
    protected $platformAccess = false;

    public function setSchoolId($schoolId)
    {
        $this->schoolId = $schoolId;
        $this->platformAccess = false;
    }

    public function allowPlatformAccess()
    {
        $this->schoolId = null;
        $this->platformAccess = true;
    }

    public function schoolId()
    {
        return $this->schoolId;
    }

    public function hasPlatformAccess()
    {
        return $this->platformAccess;
    }

    public function clear()
    {
        $this->schoolId = null;
        $this->platformAccess = false;
    }
}
