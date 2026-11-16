<?php

namespace App\Console\Commands;

use App\Services\Reports\Stock\StockDelegationConsolidationService;
use Illuminate\Console\Command;

class RepairStockDelegationIdentitiesCommand extends Command
{
    protected $signature = 'stock:repair-delegation-identities
        {--apply : Aplica la consolidación transaccional; sin esta opción solo inspecciona}';

    protected $description = 'Inspecciona o consolida las identidades duplicadas verificadas de delegaciones de Stock.';

    public function handle(StockDelegationConsolidationService $service): int
    {
        $rules = config('stock.delegation_identity_repairs', []);
        $result = $this->option('apply')
            ? $service->consolidate($rules)
            : $service->inspect($rules);

        $this->line('STOCK_DELEGATION_IDENTITY_REPAIR='.json_encode(
            [
                'mode' => $this->option('apply') ? 'apply' : 'dry-run',
                'delegations' => $result,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return self::SUCCESS;
    }
}
