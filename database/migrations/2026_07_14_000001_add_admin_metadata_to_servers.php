<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server', 'traffic_reset_day')) {
                $table->unsignedTinyInteger('traffic_reset_day')
                    ->nullable()
                    ->after('transfer_enable')
                    ->index()
                    ->comment('Monthly traffic reset day (1-31), null disables automatic reset');
            }

            if (!Schema::hasColumn('v2_server', 'last_traffic_reset_at')) {
                $table->unsignedBigInteger('last_traffic_reset_at')
                    ->nullable()
                    ->after('traffic_reset_day')
                    ->comment('Last server traffic reset timestamp');
            }

            if (!Schema::hasColumn('v2_server', 'remark')) {
                $table->text('remark')
                    ->nullable()
                    ->after('last_traffic_reset_at')
                    ->comment('Administrator-only server remark');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $columns = [];
            foreach (['traffic_reset_day', 'last_traffic_reset_at', 'remark'] as $column) {
                if (Schema::hasColumn('v2_server', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
