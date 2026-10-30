<?php

namespace App\Http\Controllers\Reports\CommercialCommissions;

use App\Http\Controllers\Controller;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CommercialCommissionFormulaSettingsController extends Controller
{
    public function __construct(
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);

        $selectedMonth = $this->formulaConfig->resolveSelectedMonth($request->query('month'));
        $isEditableMonth = $this->formulaConfig->isEditableMonth($selectedMonth, $request);

        return view('reports.commercial-commissions.settings', [
            'reportUserRole' => ReportUserAccess::role($request),
            'selectedMonth' => $selectedMonth,
            'openMonth' => $this->formulaConfig->openMonth(),
            'isEditableMonth' => $isEditableMonth,
            'canTemporarilyUnlockMonth' => $this->formulaConfig->canTemporarilyUnlockMonth($selectedMonth),
            'isTemporarilyUnlocked' => $this->formulaConfig->isTemporarilyUnlocked($request, $selectedMonth),
            'usesSharedDelegationDeliveryGoals' => $this->formulaConfig->usesSharedDelegationDeliveryGoals($selectedMonth),
            'settings' => $this->formulaConfig->forMonth($selectedMonth),
            'availableDelegations' => $this->formulaConfig->availableDelegations(
                $this->formulaConfig->forMonth($selectedMonth)
            ),
            'areaManagerDefinitions' => $this->formulaConfig->areaManagerDefinitions(),
            'availableAreaManagerDelegations' => $this->formulaConfig->availableAreaManagerDelegations(
                $this->formulaConfig->forMonth($selectedMonth)
            ),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);

        $selectedMonth = $this->formulaConfig->resolveSelectedMonth($request->input('month'));

        if (! $this->formulaConfig->isEditableMonth($selectedMonth, $request)) {
            return back()->withErrors(['month' => 'Solo se puede editar el mes abierto.'])->withInput();
        }

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'company_delivery_target' => ['nullable', 'integer', 'min:1'],
            'sales.solo_delivery_amount' => ['required', 'numeric', 'min:0'],
            'sales.shared_owner_delivery_amount' => ['required', 'numeric', 'min:0'],
            'sales.shared_secondary_delivery_amount' => ['required', 'numeric', 'min:0'],
            'purchases.commission_percent' => ['required', 'numeric', 'min:0', 'max:1'],
            'stock.days_threshold' => ['required', 'integer', 'min:0'],
            'stock.amount' => ['required', 'numeric', 'min:0'],
            'bonus.start_after_delivery' => ['required', 'integer', 'min:0'],
            'bonus.amount_per_delivery' => ['required', 'numeric', 'min:0'],
            'delivery_brackets.0.max_deliveries' => ['required', 'integer', 'min:0'],
            'delivery_brackets.0.percent' => ['required', 'numeric', 'min:0', 'max:1'],
            'delivery_brackets.1.max_deliveries' => ['required', 'integer', 'gt:delivery_brackets.0.max_deliveries'],
            'delivery_brackets.1.percent' => ['required', 'numeric', 'min:0', 'max:1'],
            'delivery_brackets.2.percent' => ['required', 'numeric', 'min:0', 'max:1'],
            'penalties.guarantee_total_threshold' => ['required', 'numeric', 'min:0'],
            'penalties.guarantee_percent' => ['required', 'numeric', 'min:0', 'max:1'],
            'penalties.reviews_low_threshold' => ['required', 'numeric', 'min:0', 'max:100'],
            'penalties.reviews_mid_threshold' => ['required', 'numeric', 'gt:penalties.reviews_low_threshold', 'max:100'],
            'penalties.reviews_low_percent' => ['required', 'numeric', 'min:0', 'max:1'],
            'penalties.reviews_mid_percent' => ['required', 'numeric', 'min:0', 'max:1'],
            'penalties.financing_percentage_threshold' => ['required', 'numeric', 'min:0', 'max:100'],
            'penalties.financing_percent' => ['required', 'numeric', 'min:0', 'max:1'],
            'delegations.goals' => ['nullable', 'array'],
            'delegations.goals.*.label' => ['required', 'string', 'max:255'],
            'delegations.goals.*.target_deliveries' => ['nullable', 'integer', 'min:0'],
            'area_manager.kpi_bases.deliveries' => ['nullable', 'numeric', 'min:0'],
            'area_manager.kpi_bases.benefit' => ['nullable', 'numeric', 'min:0'],
            'area_manager.kpi_bases.guarantee' => ['nullable', 'numeric', 'min:0'],
            'area_manager.kpi_bases.purchases' => ['nullable', 'numeric', 'min:0'],
            'area_manager.zone_keys' => ['nullable', 'array'],
            'area_manager.zone_keys.*.min_percent' => ['nullable', 'numeric', 'min:0'],
            'area_manager.zone_keys.*.multiplier' => ['nullable', 'numeric', 'min:0'],
            'area_manager.assignments' => ['nullable', 'array'],
            'area_manager.assignments.*.label' => ['required', 'string', 'max:255'],
            'area_manager.assignments.*.manager_key' => ['nullable', 'string', Rule::in(array_column($this->formulaConfig->areaManagerDefinitions(), 'key'))],
            'area_manager.assignments.*.active' => ['nullable'],
            'area_manager.assignments.*.objectives.deliveries' => ['nullable', 'numeric', 'min:0'],
            'area_manager.assignments.*.objectives.benefit' => ['nullable', 'numeric', 'min:0'],
            'area_manager.assignments.*.objectives.guarantee' => ['nullable', 'numeric', 'min:0'],
            'area_manager.assignments.*.objectives.purchases' => ['nullable', 'numeric', 'min:0'],
            'financials.financed_percentage_brackets' => ['nullable', 'array'],
            'financials.financed_percentage_brackets.*.min_percent' => ['nullable', 'numeric', 'min:0'],
            'financials.financed_percentage_brackets.*.incentive' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'financials.profitability_brackets' => ['nullable', 'array'],
            'financials.profitability_brackets.*.min_percent' => ['nullable', 'numeric', 'min:0'],
            'financials.profitability_brackets.*.incentive' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'financials.guarantee_percentage_brackets' => ['nullable', 'array'],
            'financials.guarantee_percentage_brackets.*.min_percent' => ['nullable', 'numeric', 'min:0'],
            'financials.guarantee_percentage_brackets.*.incentive' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'financials.excluded_interest_rates' => ['nullable', 'array'],
            'financials.excluded_interest_rates.*' => ['nullable', 'string', 'max:50'],
        ] + $this->productBracketRules('financing_product_brackets', 8) + $this->productBracketRules('guarantee_product_brackets', 6));

        $settings = [
            'company_delivery_target' => array_key_exists('company_delivery_target', $data) && $data['company_delivery_target'] !== null
                ? (int) $data['company_delivery_target']
                : null,
            'sales' => $data['sales'],
            'purchases' => $data['purchases'],
            'stock' => $data['stock'],
            'bonus' => $data['bonus'],
            'delivery_brackets' => [
                [
                    'max_deliveries' => (int) $data['delivery_brackets'][0]['max_deliveries'],
                    'percent' => (float) $data['delivery_brackets'][0]['percent'],
                ],
                [
                    'max_deliveries' => (int) $data['delivery_brackets'][1]['max_deliveries'],
                    'percent' => (float) $data['delivery_brackets'][1]['percent'],
                ],
                [
                    'max_deliveries' => null,
                    'percent' => (float) $data['delivery_brackets'][2]['percent'],
                ],
            ],
            'penalties' => $data['penalties'],
            'financing_product_brackets' => $this->normalizeProductBrackets($data['financing_product_brackets']),
            'guarantee_product_brackets' => $this->normalizeProductBrackets($data['guarantee_product_brackets']),
            'delegations' => [
                'goals' => $this->normalizeDelegationGoals($data['delegations']['goals'] ?? []),
            ],
            'area_manager' => [
                'kpi_bases' => [
                    'deliveries' => (float) ($data['area_manager']['kpi_bases']['deliveries'] ?? 150),
                    'benefit' => (float) ($data['area_manager']['kpi_bases']['benefit'] ?? 150),
                    'guarantee' => (float) ($data['area_manager']['kpi_bases']['guarantee'] ?? 100),
                    'purchases' => (float) ($data['area_manager']['kpi_bases']['purchases'] ?? 100),
                ],
                'zone_keys' => collect($data['area_manager']['zone_keys'] ?? [])
                    ->map(fn (array $row) => [
                        'min_percent' => (float) ($row['min_percent'] ?? 0),
                        'multiplier' => (float) ($row['multiplier'] ?? 0),
                    ])
                    ->values()
                    ->all(),
                'assignments' => $data['area_manager']['assignments'] ?? [],
            ],
            'financials' => [
                'financed_percentage_brackets' => $this->normalizeFinancialBrackets(
                    $data['financials']['financed_percentage_brackets'] ?? []
                ),
                'profitability_brackets' => $this->normalizeFinancialBrackets(
                    $data['financials']['profitability_brackets'] ?? []
                ),
                'guarantee_percentage_brackets' => $this->normalizeFinancialBrackets(
                    $data['financials']['guarantee_percentage_brackets'] ?? []
                ),
                'excluded_interest_rates' => collect($data['financials']['excluded_interest_rates'] ?? [])
                    ->map(fn (mixed $value) => trim((string) $value))
                    ->filter()
                    ->values()
                    ->all(),
            ],
        ];

        $this->formulaConfig->saveForMonth($selectedMonth->format('Y-m'), $settings);

        if ($selectedMonth->format('Y-m') !== $this->formulaConfig->openMonth()->format('Y-m')) {
            $this->formulaConfig->closeMonth($request, $selectedMonth);
        }

        return redirect()
            ->route('reports.commission-settings.index', ['month' => $selectedMonth->format('Y-m')])
            ->with(
                'status',
                $selectedMonth->format('Y-m') === $this->formulaConfig->openMonth()->format('Y-m')
                    ? 'Coeficientes de comisiones actualizados correctamente.'
                    : 'Coeficientes de comisiones actualizados correctamente. El mes se ha vuelto a cerrar.'
            );
    }

    public function unlock(Request $request): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);

        $selectedMonth = $this->formulaConfig->resolveSelectedMonth($request->input('month'));

        if (! $this->formulaConfig->unlockMonth($request, $selectedMonth)) {
            return redirect()
                ->route('reports.commission-settings.index', ['month' => $selectedMonth->format('Y-m')])
                ->withErrors(['month' => 'Ese mes no se puede abrir temporalmente para edicion.']);
        }

        return redirect()
            ->route('reports.commission-settings.index', ['month' => $selectedMonth->format('Y-m')])
            ->with('status', 'Mes abierto temporalmente para edicion. Se cerrara de nuevo al guardar.');
    }

    private function productBracketRules(string $key, int $count): array
    {
        $rules = [];

        foreach (range(0, $count - 1) as $index) {
            $rules["{$key}.{$index}.min_amount"] = ['required', 'numeric', 'min:0'];
            $rules["{$key}.{$index}.percent"] = ['required', 'numeric', 'min:0', 'max:1'];
        }

        return $rules;
    }

    private function normalizeProductBrackets(array $brackets): array
    {
        return collect($brackets)
            ->map(fn (array $bracket) => [
                'min_amount' => (float) $bracket['min_amount'],
                'percent' => (float) $bracket['percent'],
            ])
            ->sortByDesc('min_amount')
            ->values()
            ->all();
    }

    private function normalizeDelegationGoals(array $goals): array
    {
        return collect($goals)
            ->mapWithKeys(function (array $goal, string $goalKey): array {
                $label = trim((string) ($goal['label'] ?? ''));

                if (! $this->formulaConfig->shouldIncludeDelegationLabel($label !== '' ? $label : $goalKey)) {
                    return [];
                }

                $key = $this->formulaConfig->delegationKey($label !== '' ? $label : $goalKey);

                if ($key === '') {
                    return [];
                }

                return [
                    $key => [
                        'label' => $label !== '' ? $label : $goalKey,
                        'target_deliveries' => (int) ($goal['target_deliveries'] ?? 0),
                    ],
                ];
            })
            ->sortBy(fn (array $goal) => $goal['label'])
            ->all();
    }

    private function normalizeFinancialBrackets(array $brackets): array
    {
        return collect($brackets)
            ->map(fn (array $bracket) => [
                'min_percent' => (float) ($bracket['min_percent'] ?? 0),
                'incentive' => (float) ($bracket['incentive'] ?? 0),
            ])
            ->sortByDesc('min_percent')
            ->values()
            ->all();
    }
}
