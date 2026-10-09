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
        Schema::create('daily_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->date('menu_date');
            $table->string('status', 20)->default('draft');
            $table->time('served_from')->nullable();
            $table->time('served_until')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'menu_date']);
            $table->index(['menu_date', 'status']);

            // Target of the composite foreign key that keeps menu items within one restaurant.
            $table->unique(['id', 'restaurant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_menus');
    }
};
