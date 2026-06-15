<?php

namespace App\Domains\Shared\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    public const TYPE_SERVICE = 'service';
    public const TYPE_TRADING = 'trading';
    public const TYPE_MANUFACTURING = 'manufacturing';
    public const TYPE_HYBRID = 'hybrid';

    public const MATURITY_LEVEL_1 = 'level_1';
    public const MATURITY_LEVEL_2 = 'level_2';
    public const MATURITY_LEVEL_3 = 'level_3';
    public const MATURITY_LEVEL_4 = 'level_4';
    public const MATURITY_LEVEL_5 = 'level_5';

    protected $fillable = [
        'name',
        'client_group_id',
        'currency',
        'industry',
        'business_type',
        'primary_business_type',
        'secondary_business_types',
        'business_maturity',
        'onboarding_status',
        'fixed_expenses_locked_at',
        'clarity_started_on',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'clarity_started_on' => 'date',
            'fixed_expenses_locked_at' => 'datetime',
            'secondary_business_types' => 'array',
            'settings' => 'array',
        ];
    }

    public static function businessTypeOptions(): array
    {
        return [
            self::TYPE_SERVICE => 'Service',
            self::TYPE_TRADING => 'Trading',
            self::TYPE_MANUFACTURING => 'Manufacturing',
            self::TYPE_HYBRID => 'Hybrid',
        ];
    }

    public static function hybridExtensionOptions(): array
    {
        return [
            self::TYPE_SERVICE => 'Service',
            self::TYPE_TRADING => 'Trading',
            self::TYPE_MANUFACTURING => 'Manufacturing',
            'retail' => 'Retail',
            'wholesale' => 'Wholesale',
        ];
    }

    public static function businessMaturityOptions(): array
    {
        return [
            self::MATURITY_LEVEL_1 => 'Level 1 - Survival',
            self::MATURITY_LEVEL_2 => 'Level 2 - Control',
            self::MATURITY_LEVEL_3 => 'Level 3 - Visibility',
            self::MATURITY_LEVEL_4 => 'Level 4 - Optimization',
            self::MATURITY_LEVEL_5 => 'Level 5 - Virtual CFO',
        ];
    }

    public static function suggestedMaturityFromSignals(array $signals, ?string $businessType = null): string
    {
        $regularSales = (bool) ($signals['regular_sales'] ?? false);
        $costVisibility = (bool) ($signals['cost_visibility'] ?? false);
        $cashReview = (bool) ($signals['cash_review'] ?? false);
        $stockControl = (bool) ($signals['stock_control'] ?? false);
        $productionControl = (bool) ($signals['production_control'] ?? false);

        if (! $regularSales) {
            return self::MATURITY_LEVEL_1;
        }

        if ($businessType === self::TYPE_MANUFACTURING && $productionControl && $stockControl && $costVisibility && $cashReview) {
            return self::MATURITY_LEVEL_5;
        }

        if ($stockControl && $costVisibility && $cashReview) {
            return self::MATURITY_LEVEL_4;
        }

        if ($costVisibility && $cashReview) {
            return self::MATURITY_LEVEL_3;
        }

        return self::MATURITY_LEVEL_2;
    }

    public static function moduleCatalog(): array
    {
        return [
            'financial_engine' => 'Financial Engine',
            'basic_reports' => 'Basic Reports',
            'cash_position' => 'Cash Position',
            'business_health' => 'Business Health',
            'advisor' => 'Advisor',
            'client_profitability' => 'Client Profitability',
            'sku_profitability' => 'SKU Profitability',
            'explainability_engine' => 'Explainability Engine',
            'cash_intelligence' => 'Cash Intelligence',
            'forecasting' => 'Forecasting',
            'settlement_tracking' => 'Settlement Tracking',
            'capital_intelligence' => 'Capital Intelligence',
            'inventory_intelligence' => 'Inventory Intelligence',
            'inventory_to_cash_intelligence' => 'Inventory-to-Cash Intelligence',
            'full_cfo_advisor' => 'Full CFO Advisor',
            'opportunity_engine' => 'Opportunity Engine',
            'impact_engine' => 'Impact Engine',
            'daily_cfo_briefing' => 'Daily CFO Briefing',
            'decision_ranking' => 'Decision Ranking',
            'production_tracking' => 'Production Tracking',
            'material_ledger' => 'Material Ledger',
            'sku_recipe_bom' => 'SKU Recipe / BOM',
            'material_consumption' => 'Material Consumption',
            'inventory_aging' => 'Inventory Aging',
        ];
    }

    public function businessTypeLabel(): string
    {
        if ($this->business_type === self::TYPE_HYBRID) {
            $primary = $this->primaryBusinessTypeLabel();
            $extensions = collect($this->normalizedSecondaryBusinessTypes())
                ->map(fn (string $type): string => static::businessTypeOptions()[$type] ?? ucfirst($type))
                ->all();

            return trim($primary.' + '.implode(', ', $extensions), ' +,');
        }

        return static::businessTypeOptions()[$this->business_type] ?? 'Service';
    }

    public function primaryBusinessTypeLabel(): string
    {
        if ($this->business_type === self::TYPE_HYBRID && blank($this->primary_business_type)) {
            return 'Hybrid';
        }

        $type = $this->primary_business_type ?: $this->business_type;

        return static::businessTypeOptions()[$type] ?? 'Service';
    }

    public function normalizedSecondaryBusinessTypes(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($type): ?string => $this->normalizeBusinessType($type),
            $this->secondary_business_types ?? []
        ))));
    }

    public function activeBusinessTypes(): array
    {
        if ($this->business_type === self::TYPE_HYBRID) {
            return array_values(array_unique(array_filter(array_merge(
                [$this->normalizeBusinessType($this->primary_business_type)],
                $this->normalizedSecondaryBusinessTypes()
            ))));
        }

        return array_values(array_filter([
            $this->normalizeBusinessType($this->business_type),
        ]));
    }

    public function maturityRank(): int
    {
        return match ($this->business_maturity) {
            self::MATURITY_LEVEL_2 => 2,
            self::MATURITY_LEVEL_3 => 3,
            self::MATURITY_LEVEL_4 => 4,
            self::MATURITY_LEVEL_5 => 5,
            default => 1,
        };
    }

    public function maturityLabel(): string
    {
        return static::businessMaturityOptions()[$this->business_maturity] ?? static::businessMaturityOptions()[self::MATURITY_LEVEL_1];
    }

    public function isHybrid(): bool
    {
        return $this->business_type === self::TYPE_HYBRID;
    }

    public function supportsBusinessType(string $type): bool
    {
        $normalized = $this->normalizeBusinessType($type);

        return $normalized !== null && in_array($normalized, $this->activeBusinessTypes(), true);
    }

    public function supportsSkuManagement(): bool
    {
        return $this->maturityRank() >= 2 && ($this->supportsBusinessType(self::TYPE_TRADING) || $this->supportsBusinessType(self::TYPE_MANUFACTURING));
    }

    public function supportsInventoryIntelligence(): bool
    {
        return $this->maturityRank() >= 4 && ($this->supportsBusinessType(self::TYPE_TRADING) || $this->supportsBusinessType(self::TYPE_MANUFACTURING));
    }

    public function supportsProductionTracking(): bool
    {
        return $this->maturityRank() >= 5 && $this->supportsBusinessType(self::TYPE_MANUFACTURING);
    }

    public function supportsExplainability(): bool
    {
        return $this->maturityRank() >= 2;
    }

    public function supportsCashIntelligence(): bool
    {
        return $this->maturityRank() >= 3;
    }

    public function supportsCapitalIntelligence(): bool
    {
        return $this->maturityRank() >= 4;
    }

    public function supportsInventoryToCashIntelligence(): bool
    {
        return $this->supportsInventoryIntelligence();
    }

    public function supportsFullAdvisor(): bool
    {
        return $this->maturityRank() >= 5;
    }

    public function supportsOpportunityEngine(): bool
    {
        return $this->maturityRank() >= 5;
    }

    public function supportsImpactEngine(): bool
    {
        return $this->maturityRank() >= 5;
    }

    public function moduleEnabled(string $module): bool
    {
        return match ($module) {
            'financial_engine',
            'basic_reports',
            'cash_position',
            'business_health',
            'advisor' => true,
            'client_profitability',
            'sku_profitability' => $this->supportsSkuManagement(),
            'explainability_engine' => $this->supportsExplainability(),
            'cash_intelligence',
            'forecasting',
            'settlement_tracking' => $this->supportsCashIntelligence(),
            'capital_intelligence' => $this->supportsCapitalIntelligence(),
            'inventory_intelligence',
            'inventory_to_cash_intelligence',
            'inventory_aging' => $this->supportsInventoryIntelligence(),
            'full_cfo_advisor',
            'opportunity_engine',
            'impact_engine',
            'daily_cfo_briefing',
            'decision_ranking' => $this->supportsFullAdvisor(),
            'production_tracking',
            'material_ledger',
            'sku_recipe_bom',
            'material_consumption' => $this->supportsProductionTracking(),
            default => false,
        };
    }

    public function enabledModules(): array
    {
        return array_values(array_filter(array_keys(static::moduleCatalog()), fn (string $module): bool => $this->moduleEnabled($module)));
    }

    public function disabledModules(): array
    {
        return array_values(array_diff(array_keys(static::moduleCatalog()), $this->enabledModules()));
    }

    public function activationSummary(): array
    {
        return [
            'enabled' => array_intersect_key(static::moduleCatalog(), array_flip($this->enabledModules())),
            'disabled' => array_intersect_key(static::moduleCatalog(), array_flip($this->disabledModules())),
        ];
    }

    public function nextSetupStep(): string
    {
        if (blank($this->business_type)) {
            return 'Choose business type';
        }

        if ($this->business_type === self::TYPE_HYBRID && blank($this->primary_business_type)) {
            return 'Choose primary business type';
        }

        if ($this->business_type === self::TYPE_HYBRID && empty($this->normalizedSecondaryBusinessTypes())) {
            return 'Choose hybrid extensions';
        }

        if (blank($this->business_maturity)) {
            return 'Choose business maturity';
        }

        return match ($this->onboarding_status) {
            'setup' => 'Complete client profile and move to fixed expenses',
            'fixed_expenses' => 'Add fixed costs, salaries, then variable costs',
            'sku_costing' => 'Upload or enter SKU costs',
            'ready' => 'Review business health',
            default => 'Continue setup',
        };
    }

    private function normalizeBusinessType(?string $type): ?string
    {
        return match ($type) {
            self::TYPE_SERVICE, self::TYPE_TRADING, self::TYPE_MANUFACTURING => $type,
            'retail', 'wholesale' => self::TYPE_TRADING,
            self::TYPE_HYBRID, null, '' => null,
            default => $type,
        };
    }

    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OperationalEvent::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(SkuStockMovement::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function productionEntries(): HasMany
    {
        return $this->hasMany(ProductionEntry::class);
    }

    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function clientGroup(): BelongsTo
    {
        return $this->belongsTo(ClientGroup::class);
    }

    public function employeeSeatLimit(): ?int
    {
        $settings = is_array($this->settings ?? null) ? $this->settings : [];
        $limit = $settings['employee_seat_limit'] ?? null;

        if ($limit === null || $limit === '') {
            return null;
        }

        $limit = (int) $limit;

        return $limit > 0 ? $limit : null;
    }

    public function employeeSeatUsage(): array
    {
        $limit = $this->employeeSeatLimit();
        $used = $this->employeeUsers()->count();

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : max($limit - $used, 0),
        ];
    }

    public function employeeSeatAvailable(?int $ignoreUserId = null): bool
    {
        $limit = $this->employeeSeatLimit();

        if ($limit === null) {
            return true;
        }

        return $this->employeeUsers($ignoreUserId)->count() < $limit;
    }

    public function employeeUsers(?int $ignoreUserId = null)
    {
        return User::query()
            ->where('business_id', $this->id)
            ->where('is_employee', true)
            ->when(filled($ignoreUserId), fn ($query) => $query->whereKeyNot($ignoreUserId));
    }

    public function integrationSecurityDefaults(): array
    {
        $settings = is_array($this->settings ?? null) ? $this->settings : [];
        $security = is_array($settings['integration_security'] ?? null) ? $settings['integration_security'] : [];

        return [
            'webhook_secret' => $security['webhook_secret'] ?? null,
            'shared_token' => $security['shared_token'] ?? null,
            'signature_secret' => $security['signature_secret'] ?? null,
            'allow_local_bypass' => $security['allow_local_bypass'] ?? null,
        ];
    }
}
