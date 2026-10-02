<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create {--reset : Reset password even if user exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or reset admin user (username: admin, password: admin123)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $username = 'admin';
        $password = 'admin123';
        
        $existingAdmin = User::where('username', $username)->first();

        if ($existingAdmin) {
            if ($this->option('reset')) {
                // Reset password and ensure user is active
                $existingAdmin->password = Hash::make($password);
                $existingAdmin->is_active = true;
                $existingAdmin->role = 'admin';
                $existingAdmin->save();
                
                $this->info('✓ Admin user password reset successfully!');
                $this->info("Username: {$username}");
                $this->info("Password: {$password}");
            } else {
                // Check if user is active
                if (!$existingAdmin->is_active) {
                    $existingAdmin->is_active = true;
                    $existingAdmin->save();
                    $this->info('✓ Admin user activated!');
                } else {
                    $this->warn('Admin user already exists and is active.');
                    $this->info('Use --reset flag to reset the password:');
                    $this->info('php artisan admin:create --reset');
                }
            }
        } else {
            // Create new admin user
            User::create([
                'id' => (string) Str::uuid(),
                'username' => $username,
                'password' => Hash::make($password),
                'full_name' => 'مدیر سیستم',
                'role' => 'admin',
                'is_active' => true,
            ]);

            $this->info('✓ Admin user created successfully!');
            $this->info("Username: {$username}");
            $this->info("Password: {$password}");
        }

        return 0;
    }
}

