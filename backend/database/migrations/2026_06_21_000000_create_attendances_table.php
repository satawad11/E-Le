<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            $table->date('date');
            
            $table->dateTime('check_in_time')->nullable();
            $table->string('check_in_location')->nullable(); // Lat,Long
            
            $table->dateTime('check_out_time')->nullable();
            $table->string('check_out_location')->nullable(); // Lat,Long
            
            $table->decimal('distance_from_college', 10, 2)->nullable(); // Distance in meters
            
            $table->string('status')->default('Present'); // Present, Late, Absent, Leave
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Ensures one attendance record per user per day
            $table->unique(['user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
