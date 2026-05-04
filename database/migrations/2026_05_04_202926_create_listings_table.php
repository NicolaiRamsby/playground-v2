<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('bmcleasing');
            $table->unsignedBigInteger('external_id');
            $table->string('bil_nr')->nullable();
            $table->string('slug')->nullable();
            $table->string('url');
            $table->string('title');

            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->string('variant')->nullable();
            $table->string('year')->nullable();
            $table->string('color')->nullable();
            $table->string('fuel')->nullable();
            $table->string('body_type')->nullable();
            $table->string('state_of_vehicle')->nullable();
            $table->string('availability')->nullable();
            $table->string('leasing_type')->nullable();

            $table->unsignedInteger('monthly_price')->nullable();
            $table->unsignedInteger('first_payment')->nullable();
            $table->unsignedInteger('total_36mo')->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->string('mileage_per_year')->nullable();
            $table->unsignedInteger('hp')->nullable();

            $table->string('image_url')->nullable();
            $table->json('raw')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('removed_at')->nullable();

            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index('make');
            $table->index('availability');
            $table->index('removed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
