<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    /**
     * Расширяем `request_assignments.reason` с varchar(64) до varchar(512).
     *
     * Регрессия после commit de32270 (sticky 3-level): мы стали писать в
     * reason полный JSON `auto_sticky:{"kind":"client","linked":[...]}` —
     * для клиента с историей он превышает 64 символа (наблюдали 188 на
     * заявке 1063). INSERT падал, валил весь ParseRequestItemsJob,
     * ResolveKbJob не диспатчился — позиции оставались qa_status=not_assessed
     * без catalog match.
     *
     * varchar(512) с запасом под 30+ linked-id в массиве.
     */
    public function up(): void
    {
        if (! Schema::hasTable('request_assignments')) {
            return;
        }
        Schema::table('request_assignments', function (Blueprint $table) {
            $table->string('reason', 512)->change();
        });
    }

    public function down(): void
    {
        // Откат не делает downsize — рискованно если есть длинные строки.
        // no-op
    }
};
