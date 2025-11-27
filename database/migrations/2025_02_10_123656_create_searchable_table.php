<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('searchable', function (Blueprint $table) {
            $table->id();
            $table->string('searchable_type');
            $table->unsignedBigInteger('searchable_id');
            $table->text('data');
            $table->timestamps();

            // Add a unique constraint to searchable_type and searchable_id
            $table->unique(['searchable_type', 'searchable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('searchable');
    }
};
