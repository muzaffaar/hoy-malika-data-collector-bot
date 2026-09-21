<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen((string) $password) < 8) {
            throw new \RuntimeException('Set ADMIN_EMAIL and a unique ADMIN_PASSWORD of at least 8 characters.');
        }
        User::updateOrCreate(['email' => $email], ['name' => config('admin.name'), 'password' => Hash::make($password)]);
    }
}
