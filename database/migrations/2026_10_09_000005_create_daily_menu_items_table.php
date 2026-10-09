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
        Schema::create('daily_menu_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('daily_menu_id');
            $table->unsignedBigInteger('meal_id');
            // Denormalised from the menu so the composite keys below can prove the meal
            // and the menu belong to the same restaurant.
            $table->unsignedBigInteger('restaurant_id');
            $table->unsignedInteger('price_cents');
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['daily_menu_id', 'meal_id']);
            $table->index(['meal_id', 'restaurant_id']);

            $table->foreign(['daily_menu_id', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])->on('daily_menus')
                ->restrictOnDelete();
            $table->foreign(['meal_id', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])->on('meals')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_menu_items');
    }
};
