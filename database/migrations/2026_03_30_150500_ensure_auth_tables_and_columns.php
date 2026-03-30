<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->string('role')->default('admin');
                $table->timestamp('role_changed_at')->nullable();
                $table->enum('account_status', ['pending', 'active', 'suspended', 'expired', 'inactive'])->default('active');
                $table->timestamp('password_expires_at')->nullable();
                $table->boolean('is_password_expired')->default(false);
                $table->timestamp('last_password_change')->nullable();
                $table->rememberToken();
                $table->timestamps();

                $table->index('role');
                $table->index('account_status');
            });
        } else {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'email_verified_at')) {
                    $table->timestamp('email_verified_at')->nullable();
                }

                if (!Schema::hasColumn('users', 'role')) {
                    $table->string('role')->default('admin');
                    $table->index('role');
                }

                if (!Schema::hasColumn('users', 'role_changed_at')) {
                    $table->timestamp('role_changed_at')->nullable();
                }

                if (!Schema::hasColumn('users', 'account_status')) {
                    $table->enum('account_status', ['pending', 'active', 'suspended', 'expired', 'inactive'])->default('active');
                    $table->index('account_status');
                }

                if (!Schema::hasColumn('users', 'password_expires_at')) {
                    $table->timestamp('password_expires_at')->nullable();
                }

                if (!Schema::hasColumn('users', 'is_password_expired')) {
                    $table->boolean('is_password_expired')->default(false);
                }

                if (!Schema::hasColumn('users', 'last_password_change')) {
                    $table->timestamp('last_password_change')->nullable();
                }

                if (!Schema::hasColumn('users', 'remember_token')) {
                    $table->rememberToken();
                }
            });
        }

        if (!Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally left blank to avoid dropping existing auth tables/columns.
    }
};
