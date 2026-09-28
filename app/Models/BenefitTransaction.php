<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitTransaction extends Model
{
    protected $table = 'benefit_transactions';

    public function account(): BelongsTo
    {
        return $this->belongsTo(BenefitAccount::class, 'account_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(BenefitLot::class, 'lot_id');
    }

    public function transferStep(): BelongsTo
    {
        return $this->belongsTo(BenefitTransferStep::class, 'transfer_step_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(BenefitListing::class, 'listing_id');
    }

    public function importRecord(): BelongsTo
    {
        return $this->belongsTo(ImportRecord::class, 'import_record_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(BenefitTransaction::class, 'reversal_of_transaction_id');
    }
}
