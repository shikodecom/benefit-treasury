<?php

namespace App\Services;

use App\Models\HouseholdMember;
use Illuminate\Database\Eloquent\Collection;

class HouseholdMemberService
{
    public function create(array $data): HouseholdMember
    {
        $member = new HouseholdMember;
        $member->display_name = trim($data['display_name']);
        $member->relation_type = $data['relation_type'];
        $member->active = true;
        $member->save();

        return $member;
    }

    public function update(HouseholdMember $member, array $data): HouseholdMember
    {
        $member->display_name = trim($data['display_name']);
        $member->relation_type = $data['relation_type'];
        $member->save();

        return $member;
    }

    public function deactivate(HouseholdMember $member): void
    {
        $member->forceFill(['active' => false])->save();
    }

    public function activate(HouseholdMember $member): void
    {
        $member->forceFill(['active' => true])->save();
    }

    public function listActive(): Collection
    {
        return HouseholdMember::query()->where('active', true)
            ->orderBy('relation_type')->orderBy('id')->get();
    }
}
