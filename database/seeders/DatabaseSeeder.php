<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Crear usuario super_admin (sin company_id porque es super_admin)
        User::firstOrCreate(
            ['email' => 'vicbravo@delapengineering.com'],
            [
                'name' => 'Victor Bravo',
                'password' => Hash::make('Kale1428!'),
                'role' => User::ROLE_SUPER_ADMIN,
                'is_active' => true,
                'email_verified_at' => now(),
                'company_id' => null, // Super admin no tiene company
            ]
        );

        // Crear una company de ejemplo
        $company = Company::firstOrCreate(
            ['slug' => 'demo-company'],
            [
                'name' => 'Demo Company',
                'legal_name' => 'Demo Company S.A. de C.V.',
                'email' => 'contact@demo-company.com',
                'phone' => '+52 55 1234 5678',
                'country' => 'MX',
                'is_active' => true,
            ]
        );

        // Crear usuario de prueba asociado a la company
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'email_verified_at' => now(),
                'company_id' => $company->id,
            ]
        );
    }
}
