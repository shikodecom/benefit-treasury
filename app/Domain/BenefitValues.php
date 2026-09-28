<?php

namespace App\Domain;

final class BenefitValues
{
    public const PROGRAM_CATEGORIES = [
        'point', 'mile', 'e_money', 'gift', 'shareholder_benefit',
        'coupon', 'discount', 'campaign', 'other',
    ];

    public const ACTION_POLICIES = [
        'self_use', 'sell_now', 'hold', 'bundle', 'do_not_sell',
        'transfer_to_points', 'undecided',
    ];

    public const TRANSACTION_DIRECTIONS = ['in', 'out'];

    public const TRANSACTION_TYPES = [
        'opening_balance', 'earn', 'use', 'transfer_out', 'transfer_in',
        'sell', 'expire', 'adjustment_in', 'adjustment_out', 'reversal',
    ];

    public const LISTING_STATUSES = ['draft', 'listed', 'sold', 'ended_unsold', 'cancelled'];

    public const TRANSFER_STATUSES = ['planned', 'processing', 'completed', 'cancelled', 'error'];

    public const IMPORT_STATUSES = [
        'pending', 'ready', 'imported', 'skipped_duplicate',
        'skipped_out_of_scope', 'warning', 'error', 'needs_review',
    ];

    private function __construct() {}
}
