<?php

namespace App\Services;

use App\Models\BenefitListing;
use App\Models\BenefitLot;
use App\Models\BenefitTransferStep;
use App\Models\Notification;
use App\Models\NotificationPreference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BenefitNotificationService
{
    private ?array $preferences = null;

    public function __construct(private readonly BenefitDashboardService $dashboard) {}

    public function generate(): int
    {
        $today = CarbonImmutable::now('Asia/Tokyo')->startOfDay();
        $created = 0;
        $this->preferences = NotificationPreference::query()->get()->keyBy('notification_type')->all();
        $lots = $this->dashboard->lots();
        $existingLots = $this->existingMilestones('lot', $lots->pluck('id')->all());
        $pendingLots = [];
        foreach ($lots as $lot) {
            if (! $lot->expires_at) {
                continue;
            }
            $days = $this->dashboard->daysUntilExpiry($lot);
            $milestone = $this->expiryMilestone($days);
            if (! $milestone) {
                continue;
            }
            $listed = (float) $lot->listed_quantity > 0;
            $type = $days < 0 ? 'lot_expired_pending' : ($listed ? 'listing_expiry' : ($lot->action_policy === 'sell_now' ? 'sell_now_unlisted' : 'lot_expiry'));
            if (! $this->enabled($type, $milestone)) {
                continue;
            }
            $date = $lot->expires_at;
            $key = $lot->id.':'.$milestone.':'.$date;
            if (isset($existingLots[$key])) {
                continue;
            }
            $name = $lot->display_name ?: $lot->account->program->name;
            $action = $days < 0 ? '失効処理または期限修正を確認してください。' : ($listed ? '出品価格を見直してください。' : ($lot->action_policy === 'sell_now' ? '未出品です。出品を確認してください。' : $this->dashboard->recommendedAction($lot).'。'));
            $label = $days < 0 ? '期限切れ' : ($days === 0 ? '本日期限' : "期限まで{$days}日");
            $pendingLots[] = [
                'type' => $type, 'subject_type' => 'lot', 'subject_id' => $lot->id,
                'milestone_key' => $milestone, 'milestone_date' => $date,
                'priority' => $days <= 3 ? 'high' : ($days <= 14 ? 'normal' : 'low'),
                'title' => $name.' · '.$label,
                'body' => $action,
                'action_url' => route('ledger.lots.show', $lot),
                'scheduled_for' => now(), 'delivered_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ];
            $existingLots[$key] = true;
        }
        $created += $this->insertPending($pendingLots);
        if ($this->enabled('listing_ended_unsold')) {
            $activeLots = $lots->keyBy('id');
            $listings = BenefitListing::query()->with('items')->where('status', 'ended_unsold')
                ->whereNotNull('ended_at')->where('ended_at', '>=', $today->subDays(30)->toDateTimeString())->get();
            $existingListings = $this->existingMilestones('listing', $listings->pluck('id')->all());
            $pendingListings = [];
            foreach ($listings as $listing) {
                if (! $listing->items->contains(fn ($item) => $activeLots->has($item->lot_id))) {
                    continue;
                }
                $date = CarbonImmutable::parse($listing->ended_at)->setTimezone('Asia/Tokyo')->toDateString();
                $key = $listing->id.':ended_unsold:'.$date;
                if (isset($existingListings[$key])) {
                    continue;
                }
                $pendingListings[] = [
                    'type' => 'listing_ended_unsold', 'subject_type' => 'listing', 'subject_id' => $listing->id,
                    'milestone_key' => 'ended_unsold', 'milestone_date' => $date,
                    'priority' => 'normal', 'title' => '出品が売れずに終了しました',
                    'body' => '再出品または利用方針を確認してください。',
                    'action_url' => route('listings.show', $listing),
                    'scheduled_for' => now(), 'delivered_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ];
                $existingListings[$key] = true;
            }
            $created += $this->insertPending($pendingListings);
        }
        $steps = BenefitTransferStep::query()->with(['group', 'fromAccount.program', 'toAccount.program'])
            ->where('status', 'processing')->whereNotNull('expected_complete_at')
            ->whereDate('expected_complete_at', '<=', $today->addDays(3)->toDateString())->get();
        $existingSteps = $this->existingMilestones('transfer_step', $steps->pluck('id')->all());
        $pendingSteps = [];
        foreach ($steps as $step) {
            $days = (int) $today->diffInDays(CarbonImmutable::parse($step->expected_complete_at, 'Asia/Tokyo')->startOfDay(), false);
            $milestone = $this->transferMilestone($days);
            if (! $milestone) {
                continue;
            }
            $type = $days < 0 ? 'transfer_overdue' : 'transfer_due';
            if (! $this->enabled($type, $milestone)) {
                continue;
            }
            $key = $step->id.':'.$milestone.':'.$step->expected_complete_at;
            if (isset($existingSteps[$key])) {
                continue;
            }
            $pendingSteps[] = [
                'type' => $type, 'subject_type' => 'transfer_step', 'subject_id' => $step->id,
                'milestone_key' => $milestone, 'milestone_date' => $step->expected_complete_at,
                'priority' => $days <= -7 ? 'high' : 'normal',
                'title' => $step->fromAccount->program->name.' → '.$step->toAccount->program->name.' · '.($days < 0 ? '着弾予定日超過' : '着弾予定日接近'),
                'body' => $days < 0 ? '着弾状況を確認してください。' : '着弾予定日を確認してください。',
                'action_url' => route('transfers.show', $step->transfer_group_id),
                'scheduled_for' => now(), 'delivered_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ];
            $existingSteps[$key] = true;
        }
        $created += $this->insertPending($pendingSteps);

        return $created;
    }

    public function isStale(Notification $notification): bool
    {
        if ($notification->subject_type === 'lot') {
            $lot = BenefitLot::query()->find($notification->subject_id);
            if (! $lot || $lot->cancelled_at || $lot->expires_at !== $notification->milestone_date) {
                return true;
            }

            return DB::table('benefit_transactions')->where('lot_id', $lot->id)
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END), 0) AS remaining")
                ->value('remaining') <= 0;
        }
        if ($notification->subject_type === 'transfer_step') {
            $step = BenefitTransferStep::query()->find($notification->subject_id);

            return ! $step || $step->status !== 'processing' || $step->expected_complete_at !== $notification->milestone_date;
        }
        if ($notification->subject_type === 'listing') {
            $listing = BenefitListing::query()->with('items')->find($notification->subject_id);
            if (! $listing || $listing->status !== 'ended_unsold') {
                return true;
            }
            $active = $this->dashboard->lots()->keyBy('id');

            return ! $listing->items->contains(fn ($item) => $active->has($item->lot_id));
        }

        return true;
    }

    private function enabled(string $type, ?string $milestone = null): bool
    {
        $this->preferences ??= NotificationPreference::query()->get()->keyBy('notification_type')->all();
        $preference = $this->preferences[$type] ?? null;
        if ($preference && (! $preference->enabled || ! $preference->in_app_enabled)) {
            return false;
        }
        if ($milestone !== null) {
            $step = $this->preferences['milestone:'.$milestone] ?? null;
            if ($step && (! $step->enabled || ! $step->in_app_enabled)) {
                return false;
            }
        }

        return true;
    }

    private function existingMilestones(string $subjectType, array $ids): array
    {
        $existing = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Notification::query()->where('subject_type', $subjectType)->whereIn('subject_id', $chunk)
                ->get(['subject_id', 'milestone_key', 'milestone_date']) as $notification) {
                $existing[$notification->subject_id.':'.$notification->milestone_key.':'.$notification->milestone_date] = true;
            }
        }

        return $existing;
    }

    private function insertPending(array $rows): int
    {
        $created = 0;
        foreach (array_chunk($rows, 100) as $chunk) {
            $created += DB::table('notifications')->insertOrIgnore($chunk);
        }

        return $created;
    }

    private function expiryMilestone(int $days): ?string
    {
        return match (true) {
            $days < -29 => 'expired_30d', $days < -6 => 'expired_7d', $days < 0 => 'expired_1d',
            $days === 0 => 'expire_today', $days <= 3 => 'expire_3d', $days <= 7 => 'expire_7d',
            $days <= 14 => 'expire_14d', $days <= 30 => 'expire_30d', default => null,
        };
    }

    private function transferMilestone(int $days): ?string
    {
        return match (true) {
            $days < -29 => 'overdue_30d', $days < -6 => 'overdue_7d', $days < 0 => 'overdue_1d',
            $days === 0 => 'due_today', $days <= 3 => 'due_3d', default => null,
        };
    }
}
