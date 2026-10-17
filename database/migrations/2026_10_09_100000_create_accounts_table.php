<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The chart of accounts -- deliberately NOT one hardcoded set for every school (see
 * App\Support\ChartOfAccountsTemplate for the default a school can install and then edit freely).
 * `is_header` marks a pure grouping account ("1000 Assets") that journal lines may never post to --
 * only a leaf/detail account ("1100 Cash") can receive a posting. `is_system` protects the handful of
 * accounts the posting engine itself relies on by role (see the schools.default_*_account_id columns)
 * from being deleted or retyped out from under it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('type'); // asset, liability, equity, revenue, expense
            $table->string('normal_balance'); // debit, credit -- derived from type, stored for clarity
            $table->boolean('is_header')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['school_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
