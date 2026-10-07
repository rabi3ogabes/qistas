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

    /** What is being counted, as it reads after a number: "5 customers", "3 PDF statements per month". */
    public function unit(): string
    {
        return match ($this) {
            self::Customers => __('customers'),
            self::ActiveContracts => __('active contracts'),
            self::PdfStatements => __('PDF statements per month'),
            self::ApiTokens => __('API tokens'),
            self::ExportCsv, self::AdvancedReports, self::CustomBranding => '',
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
