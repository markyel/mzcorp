<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тип аутентификации ящика: password (app-password) или oauth (XOAUTH2).
 *
 * Для Yandex 360 в корпоративных аккаунтах app-passwords часто отключены —
 * поэтому oauth становится дефолтным.
 *
 * encrypted_credentials хранит JSON:
 *   - при password: { "password": "..." }
 *   - при oauth:    { "access_token": "...", "refresh_token": "...",
 *                     "expires_at": "ISO-8601", "scope": "...", "token_type": "bearer" }
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('auth_type', 16)
                ->default('oauth')
                ->after('encrypted_credentials')
                ->index()
                ->comment('password | oauth');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            if (Schema::hasColumn('mailboxes', 'auth_type')) {
                $table->dropColumn('auth_type');
            }
        });
    }
};
