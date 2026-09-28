<?php

namespace App\Http\Controllers;

use App\Models\HouseholdMember;
use App\Services\HouseholdMemberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HouseholdMemberController extends Controller
{
    private const RELATIONS = ['self', 'spouse', 'child', 'family', 'other'];

    public function index(Request $request): View
    {
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true)
            ? (int) $request->query('per_page') : 25;
        $members = HouseholdMember::query()->withCount('accounts')
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('active', true))
            ->orderByDesc('active')->orderBy('relation_type')->orderBy('id')
            ->paginate($perPage)->withQueryString();

        return view('settings.members.index', compact('members'));
    }

    public function create(): View
    {
        return view('settings.members.form', ['member' => new HouseholdMember]);
    }

    public function store(Request $request, HouseholdMemberService $service): RedirectResponse
    {
        $member = $service->create($this->validated($request));

        return redirect()->route('settings.members.edit', $member)->with('status', '名義を登録しました。');
    }

    public function edit(HouseholdMember $member): View
    {
        $member->loadCount('accounts');

        return view('settings.members.form', compact('member'));
    }

    public function update(Request $request, HouseholdMember $member, HouseholdMemberService $service): RedirectResponse
    {
        $service->update($member, $this->validated($request));

        return back()->with('status', '名義を更新しました。');
    }

    public function toggle(HouseholdMember $member, HouseholdMemberService $service): RedirectResponse
    {
        $member->active ? $service->deactivate($member) : $service->activate($member);

        return back()->with('status', '利用状態を変更しました。');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'display_name' => ['required', 'string', 'max:100', fn ($attribute, $value, $fail) => trim($value) === '' ? $fail('表示名を入力してください。') : null],
            'relation_type' => ['required', Rule::in(self::RELATIONS)],
        ]);
    }
}
