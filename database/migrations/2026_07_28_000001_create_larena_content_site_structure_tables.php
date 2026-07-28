<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Larena\Content\Database\SiteStructureTableShapeGuard;

return new class extends Migration
{
    public function up(): void
    {
        $guard = new SiteStructureTableShapeGuard(Schema::getConnection());
        $existing = $guard->preflightUp();
        if (count($existing) === count(SiteStructureTableShapeGuard::tableNames())) {
            return;
        }
        foreach (array_reverse($existing) as $table) {
            Schema::drop($table);
        }

        Schema::create('larena_content_site_structures', static function (Blueprint $table): void {
            $table->string('structure_ref', 64)->primary();
            $table->unsignedBigInteger('current_revision');
            $table->string('current_status', 32);
            $table->unsignedBigInteger('published_revision')->nullable();
            $table->timestamp('created_at', 6);
            $table->timestamp('updated_at', 6);
        });

        Schema::create('larena_content_site_structure_revisions', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('structure_ref', 64);
            $table->unsignedBigInteger('revision');
            $table->string('status', 32);
            $table->json('nodes_json');
            $table->json('seo_json');
            $table->string('created_by', 191);
            $table->string('correlation_id', 191);
            $table->timestamp('created_at', 6);
            $table->unique(['structure_ref', 'revision'], 'content_site_structure_revision_unique');
        });

        Schema::create('larena_content_redirects', static function (Blueprint $table): void {
            $table->string('type_key', 64);
            $table->string('locale', 16);
            $table->string('source_slug', 160);
            $table->string('item_ref', 64);
            $table->timestamp('created_at', 6);
            $table->timestamp('updated_at', 6);
            $table->primary(['type_key', 'locale', 'source_slug'], 'content_redirect_primary');
            $table->index(['item_ref'], 'content_redirect_item_index');
        });

        $guard->assertCompleteCompatible();
    }

    public function down(): void
    {
        $guard = new SiteStructureTableShapeGuard(Schema::getConnection());
        $guard->preflightDown();
        foreach (array_reverse(SiteStructureTableShapeGuard::tableNames()) as $table) {
            Schema::drop($table);
        }
    }
};
