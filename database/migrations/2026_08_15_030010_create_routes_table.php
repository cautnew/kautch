<?php

declare(strict_types=1);

use App\Support\Database\UserstampSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('path', 2048);
            $table->enum('http_method', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])->default('GET');
            $table->enum('destination_type', ['page', 'asset', 'redirect']);
            $table->foreignUuid('page_id')->nullable()->constrained('pages')->cascadeOnDelete();
            $table->foreignUuid('asset_id')->nullable()->constrained('assets')->cascadeOnDelete();
            $table->foreignUuid('redirect_to_route_id')->nullable()->constrained('routes')->nullOnDelete();
            $table->string('redirect_url', 2048)->nullable();
            $table->unsignedSmallInteger('http_status')->default(200);
            UserstampSchema::columns($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['website_id', 'path', 'http_method']);
            $table->index(['website_id', 'destination_type']);

            $table->comment('URL routing table: maps a path+method to a page, an asset, or a redirect to another route or an external URL.');
        });

        // Enforced via triggers, not a CHECK constraint: MariaDB (error 1901) refuses a CHECK that
        // references a column governed by a non-RESTRICT foreign key action (page_id/asset_id use
        // ON DELETE CASCADE, redirect_to_route_id uses ON DELETE SET NULL).
        DB::unprepared(
            "CREATE TRIGGER trg_routes_single_destination_insert
                BEFORE INSERT ON routes
                FOR EACH ROW
                BEGIN
                    IF (
                        (NEW.page_id IS NOT NULL) + (NEW.asset_id IS NOT NULL)
                        + (NEW.redirect_to_route_id IS NOT NULL) + (NEW.redirect_url IS NOT NULL)
                    ) != 1 THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'A route must point to exactly one of page_id, asset_id, redirect_to_route_id, or redirect_url.';
                    END IF;

                    IF (NEW.destination_type = 'page' AND NEW.page_id IS NULL)
                        OR (NEW.destination_type = 'asset' AND NEW.asset_id IS NULL)
                        OR (NEW.destination_type = 'redirect' AND NEW.redirect_to_route_id IS NULL AND NEW.redirect_url IS NULL) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'destination_type must match whichever of page_id/asset_id/redirect_to_route_id/redirect_url is set.';
                    END IF;
                END"
        );

        DB::unprepared(
            "CREATE TRIGGER trg_routes_single_destination_update
                BEFORE UPDATE ON routes
                FOR EACH ROW
                BEGIN
                    IF (
                        (NEW.page_id IS NOT NULL) + (NEW.asset_id IS NOT NULL)
                        + (NEW.redirect_to_route_id IS NOT NULL) + (NEW.redirect_url IS NOT NULL)
                    ) != 1 THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'A route must point to exactly one of page_id, asset_id, redirect_to_route_id, or redirect_url.';
                    END IF;

                    IF (NEW.destination_type = 'page' AND NEW.page_id IS NULL)
                        OR (NEW.destination_type = 'asset' AND NEW.asset_id IS NULL)
                        OR (NEW.destination_type = 'redirect' AND NEW.redirect_to_route_id IS NULL AND NEW.redirect_url IS NULL) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'destination_type must match whichever of page_id/asset_id/redirect_to_route_id/redirect_url is set.';
                    END IF;
                END"
        );

        // Also enforced via trigger rather than CHECK, for the same reason as above
        // (redirect_to_route_id is governed by an ON DELETE SET NULL foreign key).
        DB::unprepared(
            "CREATE TRIGGER trg_routes_no_self_redirect_insert
                BEFORE INSERT ON routes
                FOR EACH ROW
                BEGIN
                    IF NEW.redirect_to_route_id IS NOT NULL AND NEW.redirect_to_route_id = NEW.id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A route cannot redirect to itself.';
                    END IF;
                END"
        );

        DB::unprepared(
            "CREATE TRIGGER trg_routes_no_self_redirect_update
                BEFORE UPDATE ON routes
                FOR EACH ROW
                BEGIN
                    IF NEW.redirect_to_route_id IS NOT NULL AND NEW.redirect_to_route_id = NEW.id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A route cannot redirect to itself.';
                    END IF;
                END"
        );

        UserstampSchema::installTriggers('routes');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        UserstampSchema::dropTriggers('routes');

        DB::unprepared('DROP TRIGGER IF EXISTS trg_routes_single_destination_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_routes_single_destination_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_routes_no_self_redirect_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_routes_no_self_redirect_update');

        Schema::dropIfExists('routes');
    }
};
