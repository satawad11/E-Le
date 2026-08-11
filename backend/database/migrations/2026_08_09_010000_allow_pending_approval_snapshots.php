<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::table('leave_approvals',fn(Blueprint $t)=>$t->foreignId('approver_id')->nullable()->change());}
 public function down():void{}
};
