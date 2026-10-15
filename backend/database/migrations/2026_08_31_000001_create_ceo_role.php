<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $hrManager = Role::findOrCreate('hr_manager', 'web');
        $ceo = Role::findOrCreate('ceo', 'web');
        $ceo->syncPermissions($hrManager->permissions);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('name', 'ceo')->where('guard_name', 'web')->delete();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
