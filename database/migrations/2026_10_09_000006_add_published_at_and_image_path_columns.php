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
        Schema::table('daily_menus', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('status');
        });

        Schema::table('meals', function (Blueprint $table) {
            // Path on the public disk; the API turns it into an absolute image_url.
            $table->string('image_path')->nullable()->after('portion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_menus', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });

        Schema::table('meals', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
