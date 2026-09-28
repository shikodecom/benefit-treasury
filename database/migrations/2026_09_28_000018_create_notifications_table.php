<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 50);
            $table->string('subject_type', 50);
            $table->unsignedBigInteger('subject_id');
            $table->string('milestone_key', 50);
            $table->date('milestone_date');
            $table->string('priority', 20);
            $table->string('title', 255);
            $table->text('body');
            $table->text('action_url')->nullable();
            $table->timestamp('scheduled_for');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
            $table->unique(['type', 'subject_type', 'subject_id', 'milestone_key', 'milestone_date'], 'notifications_milestone_unique');
            $table->index(['subject_type', 'subject_id', 'milestone_date'], 'notifications_subject_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
