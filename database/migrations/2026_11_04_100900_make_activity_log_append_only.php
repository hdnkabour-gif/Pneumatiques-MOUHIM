<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The activity log is never editable (cahier des charges §16.4).
 * Enforced at database level so no code path can bypass it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION activity_log_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'activity_log is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER activity_log_no_update_delete
                BEFORE UPDATE OR DELETE ON activity_log
                FOR EACH ROW EXECUTE FUNCTION activity_log_append_only();

            CREATE TRIGGER activity_log_no_truncate
                BEFORE TRUNCATE ON activity_log
                FOR EACH STATEMENT EXECUTE FUNCTION activity_log_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS activity_log_no_truncate ON activity_log;
            DROP TRIGGER IF EXISTS activity_log_no_update_delete ON activity_log;
            DROP FUNCTION IF EXISTS activity_log_append_only();
        SQL);
    }
};
