<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeUserAdminCommand extends Command
{
    protected $signature = 'user:admin {email : E-mailadres van de user} {--revoke : Adminrechten intrekken}';

    protected $description = 'Geef of verwijder adminrechten voor de Velopred adminpagina';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if (!$user) {
            $this->error("User niet gevonden: {$email}");
            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => !$this->option('revoke')])->save();

        $status = $user->is_admin ? 'admin' : 'geen admin';
        $this->info("{$user->email} is nu {$status}.");

        return self::SUCCESS;
    }
}
