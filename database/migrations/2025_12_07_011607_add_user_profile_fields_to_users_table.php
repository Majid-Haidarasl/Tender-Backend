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
        Schema::table('users', function (Blueprint $table) {
            $table->string('company_name', 200)->nullable()->after('full_name');
            $table->string('position', 200)->nullable()->after('company_name');
            $table->string('employee_number', 50)->nullable()->after('position');
            $table->string('mobile_number', 20)->nullable()->after('employee_number');
            $table->string('tender_committee_role', 100)->nullable()->after('mobile_number');
            $table->string('avatar', 255)->nullable()->after('tender_committee_role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'company_name',
                'position',
                'employee_number',
                'mobile_number',
                'tender_committee_role',
                'avatar'
            ]);
        });
    }
};
