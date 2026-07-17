<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_responsibility_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('responsibility_code');
            $table->boolean('can_view')->default(true);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_edit')->default(true);
            $table->boolean('can_complete')->default(true);
            $table->boolean('can_review')->default(false);
            $table->boolean('can_approve')->default(false);
            $table->boolean('own_records_only')->default(false);
            $table->boolean('team_records_allowed')->default(false);
            $table->dateTime('active_from')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('assignment_note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'business_id', 'responsibility_code'], 'staff_resp_assignment_lookup');
            $table->index(['responsibility_code', 'business_id', 'is_active'], 'staff_resp_assignment_scope');
            $table->index(['expires_at', 'is_active']);
        });

        $valid = array_keys(User::staffResponsibilityOptions());

        DB::table('users')
            ->where('is_employee', true)
            ->where('responsibilities_configured', true)
            ->whereNotNull('business_id')
            ->orderBy('id')
            ->each(function (object $user) use ($valid): void {
                $responsibilities = json_decode((string) $user->staff_responsibilities, true);

                if (! is_array($responsibilities)) {
                    return;
                }

                foreach (array_values(array_intersect($responsibilities, $valid)) as $responsibility) {
                    DB::table('staff_responsibility_assignments')->insert([
                        'user_id' => $user->id,
                        'business_id' => $user->business_id,
                        'responsibility_code' => $responsibility,
                        'can_view' => true,
                        'can_create' => in_array($responsibility, ['expense_recording', 'production', 'material_stock', 'collections'], true),
                        'can_edit' => true,
                        'can_complete' => true,
                        'can_review' => in_array($responsibility, ['supervisor_review', 'bank_exceptions', 'product_repair'], true),
                        'can_approve' => false,
                        'own_records_only' => false,
                        'team_records_allowed' => $responsibility === 'supervisor_review',
                        'active_from' => null,
                        'expires_at' => null,
                        'is_active' => true,
                        'assigned_by' => null,
                        'assignment_note' => 'Backfilled from previous staff responsibility setup.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_responsibility_assignments');
    }
};
