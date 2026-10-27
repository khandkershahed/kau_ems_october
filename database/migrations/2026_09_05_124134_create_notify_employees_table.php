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
        Schema::create('notify_employees', function (Blueprint $table) {
            $table->id();
            $table->enum("notificationMedium", ["email", "sms", "notification"])->default("notification");
            $table->boolean("isRead")->default(false);
            $table->foreignId("notification_type_id")->constrained(); 
            $table->foreignId("user_id")->constrained(); 
            $table->text("message");
            $table->string("title");
            $table->string("subject");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notify_employees');
    }
};
