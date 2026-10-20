<?php

namespace App\Support;

use App\Models\CommercialCommissionApprover;
use App\Models\CommercialCommissionClosure;
use App\Models\ReportAccessSetting;
use App\Models\ReportUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ReportUserAccess
{
    private const STRATEGIC_MODULE_DEFINITIONS = [
        'summary' => [
            'label' => 'Resumen',
            'route' => 'reports.index',
        ],
        'seo-analytics' => [
            'label' => 'SEO y Analytics',
            'route' => 'reports.seo-analytics.index',
        ],
    ];

    private const REPORT_DEFINITIONS = [
        'leads' => [
            'label' => 'Leads',
            'route' => 'reports.leads.index',
            'default_minimum_role' => ReportUser::ROLE_VIEWER,
        ],
        'reservations-sales' => [
            'label' => 'Reservas / Ventas',
            'route' => 'reports.reservations-sales.index',
            'default_minimum_role' => ReportUser::ROLE_VIEWER,
        ],
        'calls' => [
            'label' => 'Llamadas',
            'route' => 'reports.calls.index',
            'default_minimum_role' => ReportUser::ROLE_VIEWER,
        ],
        'campaigns' => [
            'label' => 'Campanas',
            'route' => 'reports.campaigns.index',
            'default_minimum_role' => ReportUser::ROLE_DIRECTOR,
        ],
        'commercial-commissions' => [
            'label' => 'Comisiones Comerciales',
            'route' => 'reports.commercial-commissions.index',
            'default_minimum_role' => ReportUser::ROLE_DIRECTOR,
        ],
        'stock' => [
            'label' => 'Stock',
            'route' => 'reports.stock.index',
            'default_minimum_role' => ReportUser::ROLE_ADMIN,
        ],
    ];

    public static function current(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        if (! $request->session()->get('informes_authenticated')) {
            return null;
        }

        $user = self::reportUser($request);

        if (! $user || ! $user->is_active) {
            return null;
        }

        return [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }

    public static function role(Request $request): string
    {
        return (string) (self::current($request)['role'] ?? ReportUser::ROLE_VIEWER);
    }

    public static function displayName(Request $request): ?string
    {
        $name = trim((string) $request->session()->get('report_user_name', ''));

        if ($name !== '') {
            return $name;
        }

        $userId = $request->session()->get('report_user_id');

        if ($userId === null) {
            return null;
        }

        return ReportUser::query()->whereKey($userId)->value('name');
    }

    public static function isAdmin(Request $request): bool
    {
        return self::normalizeRole(self::role($request)) === ReportUser::ROLE_ADMIN;
    }

    public static function isDirector(Request $request): bool
    {
        return in_array(self::normalizeRole(self::role($request)), [
            ReportUser::ROLE_DIRECTOR,
            'direction',
            'direccion',
        ], true);
    }

    public static function isAreaManager(Request $request): bool
    {
        return self::canonicalRole(self::role($request)) === ReportUser::ROLE_AREA_MANAGER;
    }

    public static function isCommissionAuditor(Request $request): bool
    {
        return self::canonicalRole(self::role($request)) === ReportUser::ROLE_COMMISSION_AUDITOR;
    }

    public static function reportUser(Request $request): ?ReportUser
    {
        if ($request->attributes->has('resolved_report_user')) {
            return $request->attributes->get('resolved_report_user');
        }

        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get('report_user_id');
        $user = $id ? ReportUser::query()->with('masterDelegation')->where('is_active', true)->find($id) : null;
        $request->attributes->set('resolved_report_user', $user);

        return $user;
    }

    public static function delegationId(Request $request): ?int
    {
        return self::canonicalRole(self::role($request)) === ReportUser::ROLE_DELEGATION_MANAGER
            ? self::reportUser($request)?->master_delegation_id
            : null;
    }

    public static function delegationName(Request $request): ?string
    {
        return self::canonicalRole(self::role($request)) === ReportUser::ROLE_DELEGATION_MANAGER
            ? self::reportUser($request)?->masterDelegation?->delegation_name
            : null;
    }

    public static function salesforceUserId(Request $request): ?string
    {
        return self::canonicalRole(self::role($request)) === ReportUser::ROLE_COMMERCIAL
            ? self::reportUser($request)?->salesforce_user_id
            : null;
    }

    public static function areaZoneKey(Request $request): ?string
    {
        if (! self::isAreaManager($request)) {
            return null;
        }

        return self::reportUser($request)?->area_zone;
    }

    public static function areaZoneLabel(Request $request): ?string
    {
        return ReportUser::areaZoneLabel(self::areaZoneKey($request));
    }

    public static function canViewCampaigns(Request $request): bool
    {
        return self::canViewReport($request, 'campaigns');
    }

    public static function canViewCommercialCommissions(Request $request): bool
    {
        return self::canViewReport($request, 'commercial-commissions');
    }

    public static function canViewLeads(Request $request): bool
    {
        return self::canViewReport($request, 'leads');
    }

    public static function canViewReservationsSales(Request $request): bool
    {
        return self::canViewReport($request, 'reservations-sales');
    }

    public static function canViewCommercialPerformance(Request $request): bool
    {
        return self::isAdmin($request) || self::isDirector($request) || self::isAreaManager($request);
    }

    public static function canManageCommercialPerformanceTarget(Request $request): bool
    {
        return self::isAdmin($request);
    }

    public static function canAuditCommercialPerformance(Request $request): bool
    {
        return self::isAdmin($request) || self::isDirector($request);
    }

    public static function canViewCalls(Request $request): bool
    {
        return self::canViewReport($request, 'calls');
    }

    public static function canViewStock(Request $request): bool
    {
        return self::canViewReport($request, 'stock');
    }

    public static function canApproveStockCatalogAliases(Request $request): bool
    {
        $user = self::reportUser($request);

        return $user?->hasPermission(ReportUser::PERMISSION_STOCK_CATALOG_ALIASES_APPROVE) ?? false;
    }

    public static function canManageReportUsers(Request $request): bool
    {
        return self::isAdmin($request);
    }

    public static function canManageSeoAnalyticalRules(Request $request): bool
    {
        return self::isAdmin($request) || self::isDirector($request);
    }

    public static function canManageSeoExecutiveEmailSettings(Request $request): bool
    {
        return self::isAdmin($request) || self::isDirector($request);
    }

    public static function canExport(Request $request): bool
    {
        return self::isAdmin($request) || self::isDirector($request);
    }

    public static function canAudit(Request $request): bool
    {
        return self::isAdmin($request) || self::isDirector($request);
    }

    public static function canExportReport(Request $request, string $reportKey): bool
    {
        if (self::isAdmin($request) || self::isDirector($request)) {
            return true;
        }

        return self::isExplicitFunctionalRole($request) && self::canViewReport($request, $reportKey);
    }

    public static function canAuditReport(Request $request, string $reportKey): bool
    {
        return self::canExportReport($request, $reportKey);
    }

    public static function canSeeSyncDiagnostics(Request $request): bool
    {
        return self::isAdmin($request);
    }

    public static function canPrepareEconomicClosures(Request $request): bool
    {
        return self::isAdmin($request);
    }

    public static function canApproveEconomicClosures(Request $request): bool
    {
        return self::isAdmin($request) || self::isDirector($request);
    }

    public static function commissionApprovalScopes(Request $request): array
    {
        if (self::current($request) === null || ! self::canViewCommercialCommissions($request)) {
            return [];
        }
        if (! $request->attributes->has('commission_approvers')) {
            $request->attributes->set('commission_approvers', CommercialCommissionApprover::query()->with('user:id,is_active')->get()->keyBy('closure_scope'));
        }
        $user = self::current($request);
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(CommercialCommissionClosure::CLOSABLE_SCOPES, function (string $scope) use ($request, $user): bool {
            $assignment = $request->attributes->get('commission_approvers')->get($scope);
            if ($assignment === null) {
                return false;
            }
            if ($assignment->configured_at === null) {
                return $assignment->report_user_id === null && self::canApproveEconomicClosures($request);
            }

            return $assignment->user?->is_active && (int) $assignment->report_user_id === (int) $user['id'];
        }));
    }

    public static function canApproveCommissionClosure(Request $request, string $scope): bool
    {
        return in_array($scope, self::commissionApprovalScopes($request), true);
    }

    public static function canReopenEconomicClosures(Request $request): bool
    {
        return self::isAdmin($request) || self::isDirector($request);
    }

    public static function canManageEconomicClosures(Request $request): bool
    {
        return self::canApproveEconomicClosures($request);
    }

    public static function canViewCallContactCommissionDetails(Request $request): bool
    {
        return self::isAdmin($request);
    }

    public static function canManageFinancingPenalties(Request $request): bool
    {
        return self::isAdmin($request);
    }

    public static function canBrowseAreaManagers(Request $request): bool
    {
        return self::isAdmin($request)
            || self::isDirector($request)
            || self::canonicalRole(self::role($request)) === ReportUser::ROLE_COMMISSION_AUDITOR;
    }

    private static function normalizeRole(string $role): string
    {
        return trim(mb_strtolower($role));
    }

    private static function isExplicitFunctionalRole(Request $request): bool
    {
        return in_array(self::canonicalRole(self::role($request)), [
            ReportUser::ROLE_AREA_MANAGER,
            ReportUser::ROLE_DELEGATION_MANAGER,
            ReportUser::ROLE_MARKETING,
            ReportUser::ROLE_FINANCIAL,
            ReportUser::ROLE_COMMERCIAL,
            ReportUser::ROLE_COMMISSION_AUDITOR,
        ], true);
    }

    public static function canViewReport(Request $request, string $reportKey): bool
    {
        $currentRole = self::canonicalRole(self::role($request));

        if (array_key_exists($reportKey, self::STRATEGIC_MODULE_DEFINITIONS)) {
            return in_array($currentRole, [ReportUser::ROLE_ADMIN, ReportUser::ROLE_DIRECTOR], true);
        }

        // This operational role is intentionally isolated from the role hierarchy.
        if ($currentRole === ReportUser::ROLE_COMMISSION_AUDITOR) {
            return $reportKey === 'commercial-commissions';
        }

        if ($currentRole === ReportUser::ROLE_STOCK_ONLY) {
            return $reportKey === 'stock';
        }

        if ($currentRole === ReportUser::ROLE_AREA_MANAGER) {
            return in_array($reportKey, [
                'leads',
                'reservations-sales',
                'calls',
                'commercial-commissions',
            ], true);
        }

        if ($currentRole === ReportUser::ROLE_DELEGATION_MANAGER) {
            return in_array($reportKey, ['leads', 'calls', 'commercial-commissions'], true);
        }
        if ($currentRole === ReportUser::ROLE_MARKETING) {
            return in_array($reportKey, ['leads', 'campaigns'], true);
        }
        if ($currentRole === ReportUser::ROLE_FINANCIAL) {
            return $reportKey === 'commercial-commissions';
        }
        if ($currentRole === ReportUser::ROLE_COMMERCIAL) {
            return in_array($reportKey, ['leads', 'calls', 'commercial-commissions'], true);
        }

        $minimumRole = self::canonicalRole(self::minimumRoleForReport($reportKey, $request));

        if ($currentRole === null || $minimumRole === null) {
            return false;
        }

        return ReportUser::roleWeight($currentRole) >= ReportUser::roleWeight($minimumRole);
    }

    public static function minimumRoleForReport(string $reportKey, ?Request $request = null): string
    {
        $settings = self::minimumRolesByReport($request);
        $fallback = self::reportDefinitions()[$reportKey]['default_minimum_role'] ?? ReportUser::ROLE_ADMIN;

        return $settings[$reportKey] ?? $fallback;
    }

    public static function minimumRolesByReport(?Request $request = null): array
    {
        if ($request?->attributes->has('report_minimum_roles')) {
            return $request->attributes->get('report_minimum_roles');
        }

        $defaults = [];

        foreach (self::reportDefinitions() as $reportKey => $definition) {
            $defaults[$reportKey] = $definition['default_minimum_role'];
        }

        if (! Schema::hasTable('report_access_settings')) {
            $request?->attributes->set('report_minimum_roles', $defaults);

            return $defaults;
        }

        $stored = ReportAccessSetting::query()
            ->get(['report_key', 'minimum_role'])
            ->pluck('minimum_role', 'report_key')
            ->all();

        foreach ($stored as $reportKey => $minimumRole) {
            if (array_key_exists($reportKey, self::reportDefinitions()) && self::canonicalRole($minimumRole) !== null) {
                $defaults[$reportKey] = self::canonicalRole($minimumRole);
            }
        }

        $request?->attributes->set('report_minimum_roles', $defaults);

        return $defaults;
    }

    public static function flushResolvedSettings(): void
    {
        // Compatibility no-op. Access settings are resolved fresh on each call.
    }

    public static function reportDefinitions(): array
    {
        return self::REPORT_DEFINITIONS;
    }

    public static function navigationDefinitions(): array
    {
        return self::STRATEGIC_MODULE_DEFINITIONS + self::REPORT_DEFINITIONS;
    }

    public static function accessibleReportKeys(Request $request): array
    {
        if ($request->attributes->has('accessible_report_keys')) {
            return $request->attributes->get('accessible_report_keys');
        }

        $keys = [];

        foreach (array_keys(self::navigationDefinitions()) as $reportKey) {
            if (self::canViewReport($request, $reportKey)) {
                $keys[] = $reportKey;
            }
        }

        $request->attributes->set('accessible_report_keys', $keys);

        return $keys;
    }

    public static function defaultAccessibleRouteName(Request $request): ?string
    {
        if (self::canViewReport($request, 'summary')) {
            return self::STRATEGIC_MODULE_DEFINITIONS['summary']['route'];
        }

        return self::defaultOperationalRouteName($request);
    }

    public static function defaultOperationalRouteName(Request $request): ?string
    {
        foreach (self::reportDefinitions() as $reportKey => $definition) {
            if (self::canViewReport($request, $reportKey)) {
                return $definition['route'];
            }
        }

        return null;
    }

    public static function roleOptions(): array
    {
        return ReportUser::roleOptions();
    }

    public static function reportMinimumRoleOptions(): array
    {
        return array_filter(
            self::roleOptions(),
            static fn (string $role): bool => ! in_array($role, [
                ReportUser::ROLE_COMMISSION_AUDITOR,
                ReportUser::ROLE_STOCK_ONLY,
            ], true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private static function canonicalRole(?string $role): ?string
    {
        return match (self::normalizeRole((string) $role)) {
            ReportUser::ROLE_ADMIN => ReportUser::ROLE_ADMIN,
            ReportUser::ROLE_DIRECTOR, 'direction', 'direccion' => ReportUser::ROLE_DIRECTOR,
            ReportUser::ROLE_AREA_MANAGER, ReportUser::LEGACY_ROLE_AREA_MANAGER_OWN_AREA => ReportUser::ROLE_AREA_MANAGER,
            ReportUser::ROLE_VIEWER => ReportUser::ROLE_VIEWER,
            ReportUser::ROLE_COMMISSION_AUDITOR => ReportUser::ROLE_COMMISSION_AUDITOR,
            ReportUser::ROLE_DELEGATION_MANAGER => ReportUser::ROLE_DELEGATION_MANAGER,
            ReportUser::ROLE_MARKETING => ReportUser::ROLE_MARKETING,
            ReportUser::ROLE_FINANCIAL => ReportUser::ROLE_FINANCIAL,
            ReportUser::ROLE_COMMERCIAL => ReportUser::ROLE_COMMERCIAL,
            ReportUser::ROLE_STOCK_ONLY => ReportUser::ROLE_STOCK_ONLY,
            default => null,
        };
    }
}
