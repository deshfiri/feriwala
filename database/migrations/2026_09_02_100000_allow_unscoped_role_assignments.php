<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets a role be assigned system-wide rather than to one account (D2).
 *
 * Spatie's teams migration puts the team key in the primary key of
 * `model_has_roles` and `model_has_permissions`, which makes it NOT NULL. That
 * suits per-team roles but not Feriwala's **platform** roles — a Finance Manager
 * is not a Finance Manager *of an account*, they are one for the whole system
 * (§32.1).
 *
 * Assigning one was failing outright:
 *
 *   null value in column "account_id" violates not-null constraint
 *
 * So the column becomes nullable, with null meaning "platform-wide", and the
 * primary key is replaced by a unique index using NULLS NOT DISTINCT — without
 * that, PostgreSQL treats every null as unique and the same platform role could
 * be assigned to the same user any number of times.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE model_has_roles DROP CONSTRAINT model_has_roles_pkey;
            ALTER TABLE model_has_roles ALTER COLUMN account_id DROP NOT NULL;
            CREATE UNIQUE INDEX model_has_roles_unique
                ON model_has_roles (role_id, model_id, model_type, account_id)
                NULLS NOT DISTINCT;

            ALTER TABLE model_has_permissions DROP CONSTRAINT model_has_permissions_pkey;
            ALTER TABLE model_has_permissions ALTER COLUMN account_id DROP NOT NULL;
            CREATE UNIQUE INDEX model_has_permissions_unique
                ON model_has_permissions (permission_id, model_id, model_type, account_id)
                NULLS NOT DISTINCT;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM model_has_roles WHERE account_id IS NULL;
            DROP INDEX IF EXISTS model_has_roles_unique;
            ALTER TABLE model_has_roles ALTER COLUMN account_id SET NOT NULL;
            ALTER TABLE model_has_roles
                ADD CONSTRAINT model_has_roles_pkey
                PRIMARY KEY (account_id, role_id, model_id, model_type);

            DELETE FROM model_has_permissions WHERE account_id IS NULL;
            DROP INDEX IF EXISTS model_has_permissions_unique;
            ALTER TABLE model_has_permissions ALTER COLUMN account_id SET NOT NULL;
            ALTER TABLE model_has_permissions
                ADD CONSTRAINT model_has_permissions_pkey
                PRIMARY KEY (account_id, permission_id, model_id, model_type);
        SQL);
    }
};
