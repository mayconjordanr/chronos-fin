<?php

/**
 * Tenant.php
 * Copyright (c) 2024 CHRONOS Fin
 *
 * This file is part of CHRONOS Fin (based on Firefly III).
 */

declare(strict_types=1);

namespace FireflyIII\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

/**
 * Class Tenant
 *
 * @property int $id
 * @property string $name
 * @property string $domain
 * @property string $database
 * @property string $plan
 * @property bool $active
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $subscription_ends_at
 * @property array|null $settings
 * @property array|null $features
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'domain',
        'database',
        'plan',
        'active',
        'trial_ends_at',
        'subscription_ends_at',
        'settings',
        'features'
    ];

    protected $casts = [
        'active' => 'boolean',
        'trial_ends_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
        'settings' => 'array',
        'features' => 'array'
    ];

    protected $dates = [
        'trial_ends_at',
        'subscription_ends_at',
        'created_at',
        'updated_at'
    ];

    /**
     * Get users for this tenant
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Check if tenant is on trial
     */
    public function isOnTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Check if tenant subscription is active
     */
    public function hasActiveSubscription(): bool
    {
        return $this->subscription_ends_at && $this->subscription_ends_at->isFuture();
    }

    /**
     * Check if tenant can access the system
     */
    public function canAccess(): bool
    {
        return $this->active && ($this->isOnTrial() || $this->hasActiveSubscription());
    }

    /**
     * Get plan features
     */
    public function getPlanFeatures(): array
    {
        $defaultFeatures = [
            'basic' => [
                'max_users' => 1,
                'max_accounts' => 5,
                'max_transactions_per_month' => 100,
                'whatsapp_integration' => true,
                'basic_reports' => true,
                'custom_categories' => false,
                'api_access' => false,
                'priority_support' => false
            ],
            'pro' => [
                'max_users' => 5,
                'max_accounts' => 20,
                'max_transactions_per_month' => 1000,
                'whatsapp_integration' => true,
                'basic_reports' => true,
                'custom_categories' => true,
                'api_access' => true,
                'priority_support' => false,
                'advanced_charts' => true,
                'export_data' => true
            ],
            'enterprise' => [
                'max_users' => -1, // unlimited
                'max_accounts' => -1, // unlimited
                'max_transactions_per_month' => -1, // unlimited
                'whatsapp_integration' => true,
                'basic_reports' => true,
                'custom_categories' => true,
                'api_access' => true,
                'priority_support' => true,
                'advanced_charts' => true,
                'export_data' => true,
                'custom_branding' => true,
                'webhook_integration' => true
            ]
        ];

        return $this->features ?? $defaultFeatures[$this->plan] ?? $defaultFeatures['basic'];
    }

    /**
     * Check if tenant has a specific feature
     */
    public function hasFeature(string $feature): bool
    {
        $features = $this->getPlanFeatures();
        return isset($features[$feature]) && $features[$feature];
    }

    /**
     * Get feature limit
     */
    public function getFeatureLimit(string $feature): int
    {
        $features = $this->getPlanFeatures();
        return $features[$feature] ?? 0;
    }

    /**
     * Scope for active tenants
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope for tenants with active subscriptions
     */
    public function scopeWithActiveSubscription($query)
    {
        return $query->where(function ($q) {
            $q->where('trial_ends_at', '>', Carbon::now())
              ->orWhere('subscription_ends_at', '>', Carbon::now());
        });
    }
}