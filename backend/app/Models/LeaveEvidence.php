<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LeaveEvidence extends Model
{
    // Laravel มอง "Evidence" เป็นคำที่ไม่มีรูปพหูพจน์ จึงเดาชื่อตารางเป็น leave_evidence ซึ่งไม่มีอยู่จริง
    protected $table='leave_evidences';
    protected $guarded=[];
    protected $hidden=['storage_path'];
    protected $casts=['issued_date'=>'date','verified_at'=>'datetime','purged_at'=>'datetime'];
    public function leave(){return $this->belongsTo(Leave::class);}
    public function uploader(){return $this->belongsTo(User::class,'uploaded_by');}
    public function verifier(){return $this->belongsTo(User::class,'verified_by');}
}
