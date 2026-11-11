<?php

namespace App\Console\Commands;

use App\Models\B2B\B2bTradeDocument;
use Illuminate\Console\Command;

class ExpireB2bDocuments extends Command
{
    protected $signature = 'b2b:documents:expire {--days=0 : Also flag documents expiring within N days}';

    protected $description = 'Mark expired B2B trade documents and report upcoming expiries (P2-6)';

    public function handle(): int
    {
        $expired = B2bTradeDocument::whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->where('status', '!=', 'expired')
            ->update(['status' => 'expired']);

        $this->info("Marked {$expired} document(s) as expired.");

        $days = (int) $this->option('days');
        if ($days > 0) {
            $soon = B2bTradeDocument::whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
                ->count();

            $this->info("{$soon} document(s) expire within {$days} day(s).");
        }

        return self::SUCCESS;
    }
}
