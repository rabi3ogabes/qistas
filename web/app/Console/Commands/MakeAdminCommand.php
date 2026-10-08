<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * Makes an existing account platform staff (or takes that back). The first admin cannot be made from the website, on
 * purpose: nobody can promote themselves by registering with a particular address. Whoever runs this has the server.
 */
final class MakeAdminCommand extends Command
{
    protected $signature = 'qistas:make-admin
        {email : The e-mail address of an account that already exists}
        {--role=super_admin : admin or super_admin}
        {--revoke : Take platform rights away instead}';

    protected $description = 'Give an existing account access to the admin area (or take it away)';

    public function handle(): int
    {
        $user = User::query()->where('email', strtolower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->components->error('No account has that e-mail address. Register it on the website first, then run this again.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $user->forceFill(['platform_role' => null, 'current_tenant_id' => null])->save();
            Audit::record('admin.role_revoked', $user, userId: $user->id);
            $this->components->info("{$user->email} is no longer platform staff.");

            return self::SUCCESS;
        }

        $role = (string) $this->option('role');

        if (! in_array($role, User::ADMIN_ROLES, true)) {
            $this->components->error('The role must be one of: '.implode(', ', User::ADMIN_ROLES).'.');

            return self::FAILURE;
        }

        $user->forceFill(['platform_role' => $role])->save();
        Audit::record('admin.role_granted', $user, ['role' => $role], userId: $user->id);

        $this->components->info("{$user->email} is now {$role}.");
        $this->line('  The admin area needs two-factor sign-in: they turn it on under Security the first time they open /admin.');

        return self::SUCCESS;
    }
}
