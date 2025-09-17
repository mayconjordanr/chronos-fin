<?php

/**
 * TenantMiddleware.php
 * Copyright (c) 2024 CHRONOS Fin
 *
 * This file is part of CHRONOS Fin (based on Firefly III).
 */

declare(strict_types=1);

namespace FireflyIII\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use FireflyIII\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class TenantMiddleware
 *
 * Middleware to handle multi-tenancy for CHRONOS Fin
 */
class TenantMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $domain = $request->getHost();

        // Skip tenant resolution for local development and main domain
        if (in_array($domain, ['localhost', '127.0.0.1', 'chronos.ia.br', 'app.chronos.ia.br'], true)) {
            return $next($request);
        }

        // Find tenant by domain
        $tenant = $this->findTenantByDomain($domain);

        if (!$tenant) {
            return response()->json([
                'error' => 'Tenant not found',
                'message' => 'O domínio fornecido não está registrado no sistema.'
            ], 404);
        }

        if (!$tenant->canAccess()) {
            return response()->json([
                'error' => 'Tenant inactive',
                'message' => 'Sua assinatura expirou. Entre em contato com o suporte.'
            ], 403);
        }

        // Set tenant context
        $this->setTenantContext($tenant);

        // Store tenant in request for later use
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }

    /**
     * Find tenant by domain
     */
    private function findTenantByDomain(string $domain): ?Tenant
    {
        // Support both subdomain.chronos-fin.com and custom domains
        $tenant = Tenant::where('domain', $domain)
            ->where('active', true)
            ->first();

        if (!$tenant) {
            // Try subdomain pattern
            if (str_ends_with($domain, '.chronos.ia.br')) {
                $subdomain = str_replace('.chronos.ia.br', '', $domain);
                $tenant = Tenant::where('domain', $subdomain)
                    ->where('active', true)
                    ->first();
            }
        }

        return $tenant;
    }

    /**
     * Set tenant context for database and config
     */
    private function setTenantContext(Tenant $tenant): void
    {
        // Switch database connection for tenant
        if ($tenant->database) {
            Config::set('database.connections.tenant', [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => $tenant->database,
                'username' => env('DB_USERNAME', 'forge'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => null,
            ]);

            // Set default connection to tenant
            Config::set('database.default', 'tenant');
            DB::purge('tenant');
            DB::reconnect('tenant');
        }

        // Set tenant-specific configurations
        if ($tenant->settings) {
            foreach ($tenant->settings as $key => $value) {
                Config::set("tenant.{$key}", $value);
            }
        }

        // Store tenant globally for easy access
        app()->instance('current_tenant', $tenant);
    }
}