<?php

namespace App\Http\Controllers;

use App\Models\BenefitListing;
use App\Models\BenefitLot;
use App\Services\BenefitListingService;
use App\Services\BenefitReadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BenefitListingController extends Controller
{
    public function index(Request $request, BenefitListingService $service): View
    {
        $status = $request->query('status', 'listed');
        if (! in_array($status, ['listed', 'draft', 'sold', 'ended_unsold', 'cancelled', 'all'], true)) $status = 'listed';
        $query = BenefitListing::query()->with(['items.lot.account.program', 'items.lot.account.householdMember']);
        if ($status !== 'all') $query->where('status', $status);
        $listings = $query->orderByDesc('id')->paginate(20)->withQueryString();

        return view('listings.index', ['listings' => $listings, 'status' => $status, 'summary' => $service->salesSummary()]);
    }

    public function create(Request $request, BenefitReadService $read): View
    {
        return $this->form(new BenefitListing, $read, $request->query('lot_id'));
    }

    public function edit(BenefitListing $listing, BenefitReadService $read): View
    {
        abort_unless(in_array($listing->status, ['draft', 'listed'], true), 404);

        return $this->form($listing, $read);
    }

    private function form(BenefitListing $listing, BenefitReadService $read, ?string $selectedLotId = null): View
    {
        $listing->load('items');
        $lots = BenefitLot::query()->with(['account.program', 'account.householdMember'])->whereNull('cancelled_at')
            ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('id')->get();
        $available = [];
        foreach ($lots as $lot) {
            $available[$lot->id] = $read->lotAvailableQuantity($lot->id);
        }

        return view('listings.form', compact('listing', 'lots', 'available', 'selectedLotId'));
    }

    public function store(Request $request, BenefitListingService $service): RedirectResponse
    {
        $data = $this->validated($request);
        $listing = $service->createDraft($data, $data['items']);

        return redirect()->route('listings.show', $listing)->with('status', '下書きを作成しました。');
    }

    public function update(Request $request, BenefitListing $listing, BenefitListingService $service): RedirectResponse
    {
        $data = $this->validated($request);
        $service->updateListing($listing, $data, $data['items'], $request->boolean('confirm_policy'), $request->boolean('confirm_mixed_expiry'));

        return redirect()->route('listings.show', $listing)->with('status', '出品内容を更新しました。');
    }

    public function show(BenefitListing $listing): View
    {
        $listing->load(['items.lot.account.program', 'items.lot.account.householdMember', 'priceHistory', 'transactions']);

        return view('listings.show', compact('listing'));
    }

    public function publish(Request $request, BenefitListing $listing, BenefitListingService $service): RedirectResponse
    {
        $service->publish($listing, $request->boolean('confirm_policy'), $request->boolean('confirm_mixed_expiry'));

        return back()->with('status', '出品中にしました。');
    }

    public function changePrice(Request $request, BenefitListing $listing, BenefitListingService $service): RedirectResponse
    {
        $data = $request->validate(['listing_price_yen' => ['required', 'integer', 'min:0'], 'reason' => ['nullable', 'string', 'max:255']]);
        $service->changePrice($listing, $data['listing_price_yen'], $data['reason'] ?? null);

        return back()->with('status', '価格を変更しました。');
    }

    public function cancel(BenefitListing $listing, BenefitListingService $service): RedirectResponse
    {
        $service->cancel($listing);

        return back()->with('status', '出品を取り下げました。');
    }

    public function endUnsold(BenefitListing $listing, BenefitListingService $service): RedirectResponse
    {
        $service->endUnsold($listing);

        return back()->with('status', '売れず終了として記録しました。');
    }

    public function relist(BenefitListing $listing, BenefitListingService $service): RedirectResponse
    {
        $draft = $service->relist($listing);

        return redirect()->route('listings.show', $draft)->with('status', '新しい下書きを作成しました。');
    }

    public function sellForm(BenefitListing $listing): View
    {
        abort_unless($listing->status === 'listed', 404);
        $listing->load('items.lot.account.program');

        return view('listings.sell', compact('listing'));
    }

    public function sell(Request $request, BenefitListing $listing, BenefitListingService $service): RedirectResponse
    {
        $data = $request->validate([
            'sold_at' => ['required', 'date'], 'sold_price_yen' => ['required', 'integer', 'min:0'],
            'fee_yen' => ['nullable', 'integer', 'min:0'], 'shipping_yen' => ['nullable', 'integer', 'min:0'],
            'memo' => ['nullable', 'string'],
        ]);
        $service->sell($listing, $data['sold_price_yen'], $data['fee_yen'] ?? 0, $data['shipping_yen'] ?? 0,
            $data['sold_at'], $data['memo'] ?? null);

        return redirect()->route('listings.show', $listing)->with('status', '売却と在庫減算を記録しました。');
    }

    public function reverseSale(BenefitListing $listing, BenefitListingService $service): RedirectResponse
    {
        $service->reverseSale($listing);

        return back()->with('status', '売却を取り消し、在庫を戻しました。');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'marketplace' => ['required', 'string', 'max:100'], 'title' => ['nullable', 'string', 'max:255'],
            'listing_price_yen' => ['nullable', 'integer', 'min:0'], 'listing_url' => ['nullable', 'url:http,https'],
            'delivery_type' => ['nullable', Rule::in(['digital', 'shipping', 'handoff', 'other'])],
            'memo' => ['nullable', 'string'], 'price_reason' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array'], 'items.*' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4'],
        ]);
        $data['items'] = array_filter($data['items'], fn ($value) => $value !== null && $value !== '');

        return $data;
    }
}
