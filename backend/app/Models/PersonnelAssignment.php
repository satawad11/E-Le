<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonnelAssignment extends Model
{
    protected $fillable = ['uuid', 'user_id', 'org_unit_id', 'personnel_type_id', 'position', 'effective_from', 'effective_to'];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function personnelType()
    {
        return $this->belongsTo(PersonnelType::class);
    }

    public function personnel()
    {
        return $this->belongsTo(Personnel::class, 'user_id', 'user_id');
    }
}
