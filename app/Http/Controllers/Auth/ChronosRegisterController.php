<?php

/**
 * ChronosRegisterController.php
 * Copyright (c) 2024 CHRONOS Fin
 *
 * This file is part of CHRONOS Fin (based on Firefly III).
 */

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\Auth;

use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\User;
use FireflyIII\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Class ChronosRegisterController
 *
 * Controller for CHRONOS Fin registration system
 */
class ChronosRegisterController extends Controller
{
    /**
     * Show registration form
     */
    public function showRegistrationForm()
    {
        return view('auth.chronos-register');
    }

    /**
     * Handle registration request
     */
    public function register(Request $request)
    {
        $validator = $this->validator($request->all());

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            DB::beginTransaction();

            // Create tenant first
            $tenant = $this->createTenant($request);

            // Create user
            $user = $this->createUser($request->all(), $tenant);

            // Setup default accounts and categories for new user
            $this->setupDefaultData($user, $tenant);

            DB::commit();

            // Send welcome email
            $this->sendWelcomeEmail($user, $tenant);

            return redirect()->route('chronos.welcome', ['domain' => $tenant->domain])
                ->with('success', 'Conta criada com sucesso! Bem-vindo ao CHRONOS Fin.');

        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()
                ->withErrors(['error' => 'Erro ao criar conta: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Validate registration data
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'company_name' => ['required', 'string', 'max:255'],
            'subdomain' => ['required', 'string', 'max:50', 'unique:tenants,domain', 'regex:/^[a-zA-Z0-9-]+$/'],
            'plan' => ['required', 'in:basic,pro,enterprise'],
            'phone' => ['nullable', 'string', 'max:20'],
            'terms' => ['required', 'accepted']
        ], [
            'subdomain.regex' => 'O subdomínio deve conter apenas letras, números e hífens.',
            'subdomain.unique' => 'Este subdomínio já está em uso.',
            'terms.accepted' => 'Você deve aceitar os termos de uso.'
        ]);
    }

    /**
     * Create tenant
     */
    protected function createTenant(Request $request): Tenant
    {
        $subdomain = strtolower($request->subdomain);
        $databaseName = 'chronos_' . $subdomain;

        // Create database for tenant
        $this->createTenantDatabase($databaseName);

        $tenant = Tenant::create([
            'name' => $request->company_name,
            'domain' => $subdomain,
            'database' => $databaseName,
            'plan' => $request->plan,
            'active' => true,
            'trial_ends_at' => Carbon::now()->addDays(30), // 30-day trial
            'settings' => [
                'timezone' => 'America/Sao_Paulo',
                'currency' => 'BRL',
                'language' => 'pt_BR'
            ]
        ]);

        // Run migrations on tenant database
        $this->runTenantMigrations($databaseName);

        return $tenant;
    }

    /**
     * Create user
     */
    protected function createUser(array $data, Tenant $tenant): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'email_verified_at' => now(),
            'role' => 'owner' // First user is always owner
        ]);
    }

    /**
     * Create tenant database
     */
    protected function createTenantDatabase(string $databaseName): void
    {
        DB::statement("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    /**
     * Run migrations on tenant database
     */
    protected function runTenantMigrations(string $databaseName): void
    {
        // This would typically be done via Artisan command
        // For now, we'll copy the main database structure
        $tables = [
            'users', 'accounts', 'account_types', 'transactions', 'transaction_groups',
            'categories', 'budgets', 'bills', 'currencies', 'preferences'
        ];

        foreach ($tables as $table) {
            DB::statement("CREATE TABLE `{$databaseName}`.`{$table}` LIKE `{$table}`");
        }
    }

    /**
     * Setup default data for new tenant
     */
    protected function setupDefaultData(User $user, Tenant $tenant): void
    {
        // Switch to tenant database
        config(['database.default' => 'tenant']);
        config(['database.connections.tenant.database' => $tenant->database]);

        // Create default accounts
        $this->createDefaultAccounts($user);

        // Create default categories
        $this->createDefaultCategories($user);

        // Switch back to main database
        config(['database.default' => 'mysql']);
    }

    /**
     * Create default accounts for new user
     */
    protected function createDefaultAccounts(User $user): void
    {
        $defaultAccounts = [
            [
                'name' => 'Conta Corrente',
                'account_type' => 'asset',
                'currency' => 'BRL',
                'active' => true
            ],
            [
                'name' => 'Carteira',
                'account_type' => 'asset',
                'currency' => 'BRL',
                'active' => true
            ]
        ];

        // Implementation would create these accounts in the tenant database
    }

    /**
     * Create default categories for new user
     */
    protected function createDefaultCategories(User $user): void
    {
        $defaultCategories = [
            'Alimentação',
            'Transporte',
            'Saúde',
            'Entretenimento',
            'Educação',
            'Casa',
            'Roupas',
            'Tecnologia',
            'Viagem',
            'Outros'
        ];

        // Implementation would create these categories in the tenant database
    }

    /**
     * Send welcome email
     */
    protected function sendWelcomeEmail(User $user, Tenant $tenant): void
    {
        // Implementation for welcome email with tenant access instructions
    }

    /**
     * Show welcome page after registration
     */
    public function showWelcome(string $domain)
    {
        $tenant = Tenant::where('domain', $domain)->firstOrFail();

        return view('auth.chronos-welcome', compact('tenant'));
    }
}