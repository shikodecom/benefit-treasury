<?php

namespace App\Http\Controllers;

use App\Models\BenefitTransferStep;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Services\BenefitNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BenefitNotificationController extends Controller
{
    private const TYPES = ['lot_expiry', 'lot_expired_pending', 'sell_now_unlisted', 'listing_expiry',
        'listing_ended_unsold', 'transfer_due', 'transfer_overdue'];

    private const MILESTONES = ['expire_30d', 'expire_14d', 'expire_7d', 'expire_3d', 'expire_today',
        'expired_1d', 'expired_7d', 'expired_30d', 'due_3d', 'due_today', 'overdue_1d', 'overdue_7d', 'overdue_30d'];

    public function index(Request $request, BenefitNotificationService $service): View
    {
        $scope = $request->query('scope', 'unread');
        if (! in_array($scope, ['unread', 'all'], true)) {
            $scope = 'unread';
        }
        $notifications = Notification::query()->when($scope === 'unread', fn ($q) => $q->whereNull('read_at')->whereNull('dismissed_at'))
            ->orderByDesc('created_at')->paginate(25)->withQueryString();
        $stale = [];
        foreach ($notifications as $notification) {
            $stale[$notification->id] = $service->isStale($notification);
        }

        return view('notifications.index', compact('notifications', 'scope', 'stale'));
    }

    public function open(Notification $notification): RedirectResponse
    {
        if (! $notification->read_at) {
            $notification->read_at = now();
            $notification->save();
        }
        $url = match ($notification->subject_type) {
            'lot' => route('ledger.lots.show', $notification->subject_id),
            'transfer_step' => route('transfers.show', BenefitTransferStep::query()->find($notification->subject_id)?->transfer_group_id ?? 0),
            'listing' => route('listings.show', $notification->subject_id),
            default => route('notifications.index'),
        };

        return redirect()->to($url);
    }

    public function readAll(): RedirectResponse
    {
        Notification::query()->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('status', 'すべて既読にしました。');
    }

    public function dismiss(Notification $notification): RedirectResponse
    {
        $notification->dismissed_at = now();
        $notification->save();

        return back()->with('status', '通知を非表示にしました。');
    }

    public function preferences(): View
    {
        $preferences = NotificationPreference::query()->get()->keyBy('notification_type');
        $types = array_merge(self::TYPES, array_map(fn ($key) => 'milestone:'.$key, self::MILESTONES));

        return view('notifications.preferences', compact('preferences', 'types'));
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['nullable', 'array'], 'enabled.*' => ['nullable', 'boolean']]);
        foreach (array_merge(self::TYPES, array_map(fn ($key) => 'milestone:'.$key, self::MILESTONES)) as $type) {
            NotificationPreference::query()->updateOrCreate(['notification_type' => $type], [
                'enabled' => ! empty($data['enabled'][$type]), 'in_app_enabled' => ! empty($data['enabled'][$type]),
                'email_enabled' => false, 'push_enabled' => false,
            ]);
        }

        return back()->with('status', '通知設定を保存しました。');
    }
}
