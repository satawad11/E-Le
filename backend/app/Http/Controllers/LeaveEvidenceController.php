<?php
namespace App\Http\Controllers;
use App\Models\Leave;
use App\Models\LeaveAuditLog;
use App\Models\LeaveEvidence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
class LeaveEvidenceController extends Controller
{
    private function authorizeAccess(Request $r,Leave $leave):void{abort_unless($leave->user_id===$r->user()->id||$r->user()->can('leave.management.manage')||$r->user()->can('leave.approval.hr'),403);}
    public function index(Request $r,Leave $leave){$this->authorizeAccess($r,$leave);return $leave->evidences()->with(['uploader:id,name','verifier:id,name'])->get();}
    public function store(Request $r,Leave $leave){$this->authorizeAccess($r,$leave);$data=$r->validate(['files'=>'required|array|max:10','files.*'=>'file|mimes:pdf,jpg,jpeg,png|max:10240','evidence_type'=>'required|string|max:80','issued_date'=>'nullable|date']);$created=[];foreach($r->file('files') as $file){$path=Storage::disk('local')->putFile('private/leave-evidences',$file);$created[]=LeaveEvidence::create(['leave_id'=>$leave->id,'uploaded_by'=>$r->user()->id,'evidence_type'=>$data['evidence_type'],'original_name'=>$file->getClientOriginalName(),'storage_path'=>$path,'mime_type'=>$file->getMimeType(),'file_size'=>$file->getSize(),'sha256'=>hash_file('sha256',$file->getRealPath()),'issued_date'=>$data['issued_date']??null]);}LeaveAuditLog::create(['leave_id'=>$leave->id,'user_id'=>$r->user()->id,'action'=>'evidence_uploaded','after'=>['ids'=>collect($created)->pluck('id')],'ip_address'=>$r->ip(),'user_agent'=>$r->userAgent()]);return response()->json($created,201);}
    public function download(Request $r,LeaveEvidence $evidence){$this->authorizeAccess($r,$evidence->leave);abort_unless(Storage::disk('local')->exists($evidence->storage_path),404);return Storage::disk('local')->download($evidence->storage_path,$evidence->original_name,['Content-Type'=>$evidence->mime_type,'Cache-Control'=>'private, no-store']);}
    public function verify(Request $r,LeaveEvidence $evidence){abort_unless($r->user()->can('leave.management.manage')||$r->user()->can('leave.approval.hr'),403);$data=$r->validate(['status'=>'required|in:verified,rejected','note'=>'nullable|string|max:1000']);$evidence->update(['verification_status'=>$data['status'],'verification_note'=>$data['note']??null,'verified_by'=>$r->user()->id,'verified_at'=>now()]);return $evidence->fresh('verifier:id,name');}
}
