<?php

namespace App\Entitlements;

/**
 * Every feature the product can switch per plan. The code declares that a feature exists and what kind it is;
 * the database (plan_features) says which plan gets it. The admin matrix lists whatever is declared here.
 */
enum Feature: string
{
    case Customers = 'customers';
    case ActiveContracts = 'active_contracts';
    case PdfStatements = 'pdf_statements';
    case ExportCsv = 'export_csv';
    case AdvancedReports = 'advanced_reports';
    case CustomBranding = 'custom_branding';
    case ApiTokens = 'api_tokens';

    public function type(): FeatureType
    {
        return match ($this) {
            self::Customers, self::ActiveContracts, self::ApiTokens => FeatureType::Limit,
            self::PdfStatements => FeatureType::Quota,
            self::ExportCsv, self::AdvancedReports, self::CustomBranding => FeatureType::Toggle,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Customers => __('Customers'),
            self::ActiveContracts => __('Active contracts'),
            self::PdfStatements => __('PDF statements'),
            self::ExportCsv => __('CSV export'),
            self::AdvancedReports => __('Advanced reports'),
            self::CustomBranding => __('Custom branding'),
            self::ApiTokens => __('API access tokens'),
        };
    }

    /** Where the admin's cockpit files it. Exhaustive on purpose: a new case must say where it belongs. */
    public function group(): FeatureGroup
    {
        return match ($this) {
            self::Customers, self::ActiveContracts, self::PdfStatements, self::ExportCsv,
            self::AdvancedReports, self::CustomBranding, self::ApiTokens => FeatureGroup::Core,
        };
    }

    /** One sentence for the admin's card: what the feature is, in plain words. */
    public function description(): string
    {
        return match ($this) {
            self::Customers => __('How many customers a workspace can keep on its lists.'),
            self::ActiveContracts => __('How many contracts can be running at the same time.'),
            self::PdfStatements => __('Customer statements as PDF files, with a monthly allowance.'),
            self::ExportCsv => __('Spreadsheet downloads of customers, contracts and payments.'),
            self::AdvancedReports => __('Ageing, monthly collections and other deeper reports.'),
            self::CustomBranding => __('The shop\'s own logo and colour on its documents.'),
            self::ApiTokens => __('Access tokens that let other software connect to a workspace.'),
        };
    }

    /**
     * What happens to existing data and running work when the admin switches this off. Shown in the confirmation
     * before the admin does it. A switch never deletes anything.
     */
    public function offBehaviour(): string
    {
        return match ($this) {
            self::Customers, self::ActiveContracts, self::ApiTokens => __('Core feature, always on. The plan sets the limit; going over it never removes anything that exists.'),
            self::PdfStatements => __('Core feature, always on. The plan sets a monthly allowance; documents already made stay available.'),
            self::ExportCsv, self::AdvancedReports, self::CustomBranding => __('Core feature, always on. The plan decides who has it; nothing is deleted when a plan changes.'),
        };
    }

    /**
     * The features that must be on for this one to work. Declared in code and checked to be free of cycles.
     *
     * @return list<Feature>
     */
    public function dependsOn(): array
    {
        return match ($this) {
            self::Customers, self::ActiveContracts, self::PdfStatements, self::ExportCsv,
            self::AdvancedReports, self::CustomBranding, self::ApiTokens => [],
        };
    }

    /** 'workspace' features are assigned to plans; 'platform' ones (membership billing) are only switched. */
    public function scope(): string
    {
        return 'workspace';
    }

    /** A feature that already worked before the switch system: always on, its platform switch cannot be changed. */
    public function isCore(): bool
    {
        return $this->group() === FeatureGroup::Core;
    }

    /** Where a feature's switch starts: core features on (nothing changes for anyone), everything new dark. */
    public function launchState(): PlatformState
    {
        return $this->isCore() ? PlatformState::On : PlatformState::Off;
    }

    /**
     * What is being counted, as it reads after a number: "5 customers", "1 API token", "3 PDF statements per month".
     * Pluralised by $count in every language (Arabic has six forms); an on/off feature counts nothing.
     */
    public function unit(int $count = 5): string
    {
        return $this->type() === FeatureType::Toggle ? '' : trans_choice("units.{$this->value}", $count);
    }

    /** One line for a plan's feature list: "Up to 5 customers", "Unlimited customers", "CSV export". */
    public function summary(bool $enabled, ?int $limit): string
    {
        if (! $enabled) {
            return __('Not included');
        }
        if ($this->type() === FeatureType::Toggle) {
            return $this->label();
        }

        return $limit === null
            ? __('Unlimited :unit', ['unit' => $this->unit()])
            : __('Up to :count :unit', ['count' => $limit, 'unit' => $this->unit($limit)]);
    }

    /** The short value for a comparison table cell: "5", "Unlimited", "3 / month", "Included", "Not included". */
    public function shortValue(bool $enabled, ?int $limit): string
    {
        if (! $enabled) {
            return __('Not included');
        }

        return match ($this->type()) {
            FeatureType::Toggle => __('Included'),
            FeatureType::Limit => $limit === null ? __('Unlimited') : (string) $limit,
            FeatureType::Quota => $limit === null ? __('Unlimited') : __(':count / month', ['count' => $limit]),
        };
    }

    /**
     * The value a plan has until the admin sets it. The built-in plans ship with the documented Free and
     * Pro allowances; any other plan starts with nothing (null) so a new plan never grants by accident.
     * Adding a case to this enum forces a Free default here (the match is exhaustive).
     *
     * @return array{enabled: bool, limit: ?int}|null
     */
    public function defaultFor(string $planKey): ?array
    {
        return match ($planKey) {
            'free' => match ($this) {
                self::Customers, self::ActiveContracts => ['enabled' => true, 'limit' => 5],
                self::PdfStatements => ['enabled' => true, 'limit' => 3],
                self::ApiTokens => ['enabled' => true, 'limit' => 1],
                self::ExportCsv, self::AdvancedReports, self::CustomBranding => ['enabled' => false, 'limit' => null],
            },
            'pro' => ['enabled' => true, 'limit' => null],
            default => null,
        };
    }
}
