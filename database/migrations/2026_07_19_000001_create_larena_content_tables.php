<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Larena\Content\Database\ContentOwnedTableShapeGuard;

return new class extends Migration
{
    public function up(): void
    {
        $guard = new ContentOwnedTableShapeGuard(Schema::getConnection());
        $plan = $guard->preflightUp();

        if ($plan['action'] === ContentOwnedTableShapeGuard::UP_NO_OP) {
            return;
        }

        foreach ($plan['drop'] as $table) {
            Schema::drop($table);
        }

        Schema::create('larena_content_types', static function (Blueprint $table): void {
            $table->string('type_key', 64)->primary();
            $table->unsignedBigInteger('current_version');
            $table->timestamp('created_at', 6);
            $table->timestamp('updated_at', 6);
        });

        Schema::create('larena_content_type_versions', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('type_key', 64);
            $table->unsignedBigInteger('version');
            $table->string('storage_schema_ref', 191);
            $table->unsignedBigInteger('storage_schema_version');
            $table->char('schema_hash', 64);
            $table->text('projection_contract');
            $table->json('safe_metadata');
            $table->string('created_by', 191);
            $table->string('correlation_id', 191);
            $table->timestamp('created_at', 6);
            $table->unique(
                ['type_key', 'version'],
                'content_type_version_unique',
            );
            $table->unique(
                ['storage_schema_ref', 'storage_schema_version'],
                'content_storage_schema_version_unique',
            );
        });

        Schema::create('larena_content_items', static function (Blueprint $table): void {
            $table->string('item_ref', 64)->primary();
            $table->string('type_key', 64);
            $table->string('locale', 16);
            $table->unsignedBigInteger('current_revision');
            $table->string('current_slug', 160);
            $table->string('current_status', 32);
            $table->string('current_visibility', 32);
            $table->unsignedBigInteger('published_revision')->nullable();
            $table->string('published_slug', 160)->nullable();
            $table->timestamp('published_at', 6)->nullable();
            $table->timestamp('created_at', 6);
            $table->timestamp('updated_at', 6);
            $table->index(
                ['type_key', 'locale', 'current_status', 'current_visibility', 'item_ref'],
                'content_item_listing_index',
            );
            $table->index(
                ['type_key', 'locale', 'published_revision', 'item_ref'],
                'content_item_published_index',
            );
        });

        Schema::create('larena_content_item_revisions', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('item_ref', 64);
            $table->unsignedBigInteger('revision');
            $table->string('type_key', 64);
            $table->string('locale', 16);
            $table->unsignedBigInteger('type_version');
            $table->string('storage_schema_ref', 191);
            $table->unsignedBigInteger('storage_schema_version');
            $table->string('storage_record_ref', 191);
            $table->unsignedBigInteger('storage_record_version');
            $table->string('slug', 160);
            $table->string('status', 32);
            $table->string('visibility', 32);
            $table->unsignedInteger('attachment_count');
            $table->string('created_by', 191);
            $table->string('correlation_id', 191);
            $table->timestamp('created_at', 6);
            $table->unique(
                ['item_ref', 'revision'],
                'content_revision_unique',
            );
            $table->index(
                ['storage_schema_ref', 'storage_record_ref', 'storage_record_version'],
                'content_revision_storage_index',
            );
        });

        Schema::create(
            'larena_content_item_revision_attachments',
            static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('item_ref', 64);
                $table->unsignedBigInteger('revision');
                $table->unsignedInteger('position');
                $table->string('logical_file_ref', 191);
                $table->string('role', 64);
                $table->timestamp('created_at', 6);
                $table->unique(
                    ['item_ref', 'revision', 'position'],
                    'content_attachment_position_unique',
                );
                $table->unique(
                    ['item_ref', 'revision', 'logical_file_ref', 'role'],
                    'content_attachment_identity_unique',
                );
            },
        );

        Schema::create('larena_content_routes', static function (Blueprint $table): void {
            $table->string('type_key', 64);
            $table->string('locale', 16);
            $table->string('slug', 160);
            $table->string('item_ref', 64);
            $table->unsignedBigInteger('current_revision')->nullable();
            $table->unsignedBigInteger('published_revision')->nullable();
            $table->timestamp('created_at', 6);
            $table->timestamp('updated_at', 6);
            $table->primary(
                ['type_key', 'locale', 'slug'],
                'content_route_primary',
            );
            $table->index(
                ['item_ref', 'current_revision'],
                'content_route_current_item_index',
            );
            $table->index(
                ['item_ref', 'published_revision'],
                'content_route_published_item_index',
            );
        });

        $guard->assertCompleteCompatible();
    }

    public function down(): void
    {
        $tables = (new ContentOwnedTableShapeGuard(Schema::getConnection()))->preflightDown();

        foreach ($tables as $table) {
            Schema::drop($table);
        }
    }
};
