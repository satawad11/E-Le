<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\PersonnelType;
use App\Models\User;
use Illuminate\Http\Request;
class PersonnelTypeController extends Controller
{
    public function store(Request $r){$data=$r->validate(['code'=>'required|string|max:50|unique:personnel_types,code','name'=>'required|string|max:255','description'=>'nullable|string','is_active'=>'boolean']);return response()->json(PersonnelType::create($data),201);}
    public function update(Request $r,PersonnelType $personnelType){$data=$r->validate(['code'=>'required|string|max:50|unique:personnel_types,code,'.$personnelType->id,'name'=>'required|string|max:255','description'=>'nullable|string','is_active'=>'boolean']);$personnelType->update($data);return $personnelType;}
    public function assign(Request $r,User $user){$data=$r->validate(['personnel_type_id'=>'required|exists:personnel_types,id']);$user->update($data);return $user->fresh('personnelType');}
}
