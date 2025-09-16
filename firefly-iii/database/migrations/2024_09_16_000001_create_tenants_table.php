<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to create tenants table for CHRONOS Fin multi-tenancy
 */
class CreateTenantsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('domain')->unique();
            $table->string('database')->unique();
            $table->string('plan')->default('basic'); // basic, pro, enterprise
            $table->boolean('active')->default(true);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            $table->json('settings')->nullable();
            $table->json('features')->nullable(); // enabled features per plan
            $table->timestamps();

            $table->index(['domain', 'active']);
            $table->index(['plan', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tenants');
    }