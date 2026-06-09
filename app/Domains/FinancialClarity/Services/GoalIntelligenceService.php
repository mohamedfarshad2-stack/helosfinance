<?php

namespace App\Domains\FinancialClarity\Services;

use App\Domains\Shared\Models\Business;
use Illuminate\Support\Arr;

class GoalIntelligenceService
{
    public function __construct(
        private readonly BreakEvenIntelligenceService $breakEvenIntelligence,
        private readonly BusinessHealthSnapshotService $snapshots,
        private readonly RevenuePipelineService $revenuePipeline,
    ) {
    }

    public static function goalTypeOptions(): array
    {
        return [
            'profit' => 'Monthly Profit Goal',
            'revenue' => 'Monthly Revenue Goal',
            'deliveries' => 'Monthly Delivery Goal',
            'collections' => 'Monthly Collection Goal',
        ];
    }

    public static function goalTypeLabel(string $type): string
    {
        return static::goalTypeOptions()[$type] ?? static::goalTypeOptions()['profit'];
    }

    public function goalSettings(Business $business): array
    {
        $settings = is_array($business->settings ?? null) ? $business->settings : [];
        $goal = is_array($settings['goal'] ?? null) ? $settings['goal'] : [];

        return [
            'type' => (string) ($goal['type'] ?? 'profit'),
            'amount' => (float) ($goal['amount'] ?? 0),
        ];
    }

    public function saveGoal(Business $business, array $payload): Business
    {
        $type = (string) ($payload['goal_type'] ?? 'profit');
        $amount = (float) ($payload['goal_amount'] ?? 0);

        $settings = is_array($business->settings ?? null) ? $business->settings : [];

        if ($amount <= 0) {
            unset($settings['goal']);
        } else {
            $settings['goal'] = [
                'type' => array_key_exists($type, static::goalTypeOptions()) ? $type : 'profit',
                'amount' => round($amount, 2),
            ];
        }

        $business->forceFill(['settings' => $settings])->save();

        return $business->fresh();
    }

    public function forCurrentMonth(Business $business): array
    {
        $summary = $this->snapshots->currentMonthSummary($business);
        $breakEven = $this->breakEvenIntelligence->forCurrentMonth($business);
        $pipeline = $this->revenuePipeline->forCurrentMonth($business);
        $settings = $this->goalSettings($business);
        $configured = (float) $settings['amount'] > 0;
        $goalType = array_key_exists($settings['type'], static::goalTypeOptions()) ? $settings['type'] : 'profit';

        $currentRevenue = (float) ($summary['revenue_total'] ?? 0);
        $currentProfit = (float) ($summary['estimated_profit'] ?? 0);
        $currentDeliveries = max((int) ($breakEven['progress']['current_deliveries'] ?? 0), 0);
        $currentCollections = (float) ($pipeline['total_collected_revenue'] ?? 0);

        $avgRevenuePerDelivery = $currentDeliveries > 0 ? $currentRevenue / $currentDeliveries : null;
        $avgCollectionsPerDelivery = $currentDeliveries > 0 ? $currentCollections / $currentDeliveries : $avgRevenuePerDelivery;
        $avgContributionPerDelivery = max((float) ($breakEven['contribution']['per_delivered_order'] ?? 0), 0.0);
        $contributionMargin = max((float) ($breakEven['contribution']['margin_percent'] ?? 0), 0.0) / 100;
        $target = max((float) $settings['amount'], 0);

        $current = match ($goalType) {
            'revenue' => $currentRevenue,
            'deliveries' => (float) $currentDeliveries,
            'collections' => $currentCollections,
            default => $currentProfit,
        };

        $gap = $configured ? max($target - $current, 0) : null;
        $progress = $configured && $target > 0 ? max(0.0, min(($current / $target) * 100, 100.0)) : 0.0;

        $remainingRevenue = null;
        $remainingDeliveries = null;
        $remainingOrders = null;
        $remainingCollections = null;

        if ($configured) {
            match ($goalType) {
                'profit' => $this->shapeProfitGoal($gap, $contributionMargin, $avgContributionPerDelivery, $remainingRevenue, $remainingDeliveries, $remainingOrders),
                'revenue' => $this->shapeRevenueGoal($gap, $avgRevenuePerDelivery, $avgContributionPerDelivery, $remainingRevenue, $remainingDeliveries, $remainingOrders),
                'deliveries' => $this->shapeDeliveryGoal($gap, $avgRevenuePerDelivery, $avgContributionPerDelivery, $remainingRevenue, $remainingDeliveries, $remainingOrders),
                'collections' => $this->shapeCollectionGoal($gap, $avgCollectionsPerDelivery, $avgContributionPerDelivery, $remainingRevenue, $remainingDeliveries, $remainingOrders, $remainingCollections),
                default => null,
            };
        }

        $pressure = $breakEven['pressure'] ?? [];
        $topObstacle = $pressure['top_obstacles'][0] ?? null;
        $whatHelpsMost = $pressure['what_helps_most'][0] ?? null;
        $fastestPath = $this->buildFastestPath($goalType, $topObstacle, $whatHelpsMost, $remainingDeliveries, $remainingRevenue);

        $headline = match (true) {
            ! $configured => 'Set a monthly goal to start tracking progress.',
            $gap <= 0 => static::goalTypeLabel($goalType).' is already covered for the month.',
            $goalType === 'profit' => 'You are still chasing your profit target.',
            $goalType === 'revenue' => 'You are still chasing your revenue target.',
            $goalType === 'deliveries' => 'You are still chasing your delivery target.',
            default => 'You are still chasing your collection target.',
        };

        $goalLabel = static::goalTypeLabel($goalType);

        $summaryLines = array_values(array_filter([
            $configured ? $goalLabel.': LKR '.number_format($target, 2) : 'Set a goal amount to start tracking.',
            $configured ? 'Current position: '.($goalType === 'deliveries' ? number_format($current, 0).' deliveries' : 'LKR '.number_format($current, 2)) : null,
            $configured ? 'Still needed: '.($gap > 0 ? ($goalType === 'deliveries' ? number_format($gap, 0).' deliveries' : 'LKR '.number_format($gap, 2)) : 'Covered') : null,
            $configured ? 'Progress: '.number_format($progress, 2).'%.' : null,
        ]));

        return [
            'activated' => true,
            'configured' => $configured,
            'headline' => $headline,
            'summary' => $summaryLines,
            'goal' => [
                'type' => $goalType,
                'label' => $goalLabel,
                'target' => $configured ? round($target, 2) : null,
                'current' => $configured ? round($current, 2) : null,
                'gap' => $configured ? round($gap ?? 0, 2) : null,
                'progress_percent' => round($progress, 2),
            ],
            'cards' => [
                [
                    'title' => 'Your Goal',
                    'value' => $configured
                        ? ($goalType === 'deliveries'
                            ? number_format($target, 0).' deliveries'
                            : 'LKR '.number_format($target, 2))
                        : 'Set one',
                    'status' => $configured ? 'Active' : 'Not set',
                    'tone' => $configured ? 'info' : 'warning',
                    'note' => $configured ? $goalLabel : 'Choose a goal type and amount above.',
                ],
                [
                    'title' => 'Current Progress',
                    'value' => $configured
                        ? number_format($progress, 2).'%'
                        : '0%',
                    'status' => $configured ? ($gap <= 0 ? 'Covered' : 'In progress') : 'Waiting',
                    'tone' => $configured && $gap <= 0 ? 'success' : 'info',
                    'note' => $configured
                        ? ($goalType === 'deliveries'
                            ? number_format($current, 0).' deliveries completed'
                            : 'Current position: LKR '.number_format($current, 2))
                        : 'No goal progress is being tracked yet.',
                ],
                [
                    'title' => 'Still Needed',
                    'value' => $configured
                        ? ($goalType === 'deliveries'
                            ? ($gap > 0 ? number_format($gap, 0).' deliveries' : '0 deliveries')
                            : 'LKR '.number_format($gap ?? 0, 2))
                        : 'Set a goal',
                    'status' => $configured ? ($gap <= 0 ? 'Covered' : 'Pending') : 'Not set',
                    'tone' => $configured && $gap <= 0 ? 'success' : 'warning',
                    'note' => $configured
                        ? ($goalType === 'profit'
                            ? 'Profit still needed to hit the target.'
                            : 'Money still needed to reach the target.')
                        : 'Add a monthly goal to see the gap.',
                ],
                [
                    'title' => 'What Is Slowing You Down',
                    'value' => $topObstacle['label'] ?? 'Set a goal',
                    'status' => $configured ? ($topObstacle ? 'Watch' : 'Calm') : 'Not set',
                    'tone' => $configured && $topObstacle ? 'warning' : 'success',
                    'note' => $configured
                        ? ($topObstacle ? 'LKR '.number_format((float) ($topObstacle['amount'] ?? 0), 2) : 'No major pressure is showing yet.')
                        : 'Goal pressure will show after a goal is saved.',
                ],
                [
                    'title' => 'Fastest Path Forward',
                    'value' => $fastestPath['value'],
                    'status' => $configured ? 'Next' : 'Not set',
                    'tone' => $configured ? 'success' : 'warning',
                    'note' => $fastestPath['note'],
                ],
            ],
            'pressure' => [
                'headline' => $pressure['headline'] ?? 'Goal pressure is light for now.',
                'top_obstacles' => $pressure['top_obstacles'] ?? [],
                'what_helps_most' => $pressure['what_helps_most'] ?? [],
                'fastest_path' => $fastestPath,
            ],
            'delivery_requirements' => [
                'additional_deliveries' => $remainingDeliveries === null ? null : (int) $remainingDeliveries,
                'additional_orders' => $remainingOrders === null ? null : (int) $remainingOrders,
                'additional_revenue' => $remainingRevenue === null ? null : round($remainingRevenue, 2),
                'additional_collections' => $remainingCollections === null ? null : round($remainingCollections, 2),
            ],
            'actions' => array_values(array_filter([
                $configured ? 'Keep the strongest product or revenue stream moving first.' : 'Set a monthly goal first.',
                $topObstacle ? 'Reduce '.$topObstacle['label'].' before chasing extra volume.' : null,
                $whatHelpsMost ? 'Push '.$whatHelpsMost['label'].' because it is helping the month most.' : null,
            ])),
        ];
    }

    private function shapeProfitGoal(?float $gap, float $contributionMargin, float $avgContributionPerDelivery, ?float &$remainingRevenue, ?float &$remainingDeliveries, ?float &$remainingOrders): void
    {
        if ($gap === null) {
            return;
        }

        $remainingRevenue = $contributionMargin > 0 ? $gap / $contributionMargin : null;
        $remainingDeliveries = $avgContributionPerDelivery > 0 ? ceil($gap / $avgContributionPerDelivery) : null;
        $remainingOrders = $remainingDeliveries;
    }

    private function shapeRevenueGoal(?float $gap, ?float $avgRevenuePerDelivery, float $avgContributionPerDelivery, ?float &$remainingRevenue, ?float &$remainingDeliveries, ?float &$remainingOrders): void
    {
        if ($gap === null) {
            return;
        }

        $remainingRevenue = $gap;
        $remainingDeliveries = $avgRevenuePerDelivery && $avgRevenuePerDelivery > 0 ? ceil($gap / $avgRevenuePerDelivery) : null;
        $remainingOrders = $remainingDeliveries;
    }

    private function shapeDeliveryGoal(?float $gap, ?float $avgRevenuePerDelivery, float $avgContributionPerDelivery, ?float &$remainingRevenue, ?float &$remainingDeliveries, ?float &$remainingOrders): void
    {
        if ($gap === null) {
            return;
        }

        $remainingDeliveries = $gap;
        $remainingOrders = $gap;
        $remainingRevenue = $avgRevenuePerDelivery && $avgRevenuePerDelivery > 0 ? $gap * $avgRevenuePerDelivery : null;
    }

    private function shapeCollectionGoal(?float $gap, ?float $avgCollectionsPerDelivery, float $avgContributionPerDelivery, ?float &$remainingRevenue, ?float &$remainingDeliveries, ?float &$remainingOrders, ?float &$remainingCollections): void
    {
        if ($gap === null) {
            return;
        }

        $remainingCollections = $gap;
        $remainingRevenue = $gap;
        $remainingDeliveries = $avgCollectionsPerDelivery && $avgCollectionsPerDelivery > 0 ? ceil($gap / $avgCollectionsPerDelivery) : null;
        $remainingOrders = $remainingDeliveries;
    }

    private function buildFastestPath(string $goalType, ?array $topObstacle, ?array $whatHelpsMost, ?float $remainingDeliveries, ?float $remainingRevenue): array
    {
        $value = 'Set a goal first';
        $note = 'No goal is being tracked yet.';

        if ($whatHelpsMost) {
            $value = (string) ($whatHelpsMost['label'] ?? 'Keep the strongest mix moving');
            $note = 'This is the strongest current helping driver.';
        }

        if ($topObstacle && $whatHelpsMost) {
            $value = ($whatHelpsMost['label'] ?? 'Keep the strongest mix moving').' and reduce '.($topObstacle['label'] ?? 'the biggest obstacle');
            $note = 'This is the shortest path using current truth.';
        }

        if ($goalType === 'deliveries' && $remainingDeliveries !== null) {
            $note = 'You need '.$remainingDeliveries.' more deliveries at the current mix.';
        }

        if (in_array($goalType, ['profit', 'revenue', 'collections'], true) && $remainingRevenue !== null) {
            $note = 'You still need LKR '.number_format($remainingRevenue, 2).' at the current mix.';
        }

        return [
            'value' => $value,
            'note' => $note,
        ];
    }
}
