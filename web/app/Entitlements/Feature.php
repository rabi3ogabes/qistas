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
    case Members = 'members';
    case FlexibleSchedules = 'flexible_schedules';
    case Investors = 'investors';
    case OpenContracts = 'open_contracts';
    case ContractItems = 'contract_items';
    case CustomerTags = 'customer_tags';

    /** How many customers the free plan keeps (tests and copy read this rather than repeating the number). */
    public const FREE_CUSTOMERS = 20;

    public function type(): FeatureType
    {
        return match ($this) {
            self::Customers, self::ActiveContracts, self::ApiTokens, self::Members, self::Investors => FeatureType::Limit,
            self::PdfStatements => FeatureType::Quota,
            self::ExportCsv, self::AdvancedReports, self::CustomBranding, self::FlexibleSchedules, self::OpenContracts, self::ContractItems, self::CustomerTags => FeatureType::Toggle,
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
            self::Members => __('Team members'),
            self::FlexibleSchedules => __('Flexible schedules'),
            self::Investors => __('Investors'),
            self::OpenContracts => __('Open contracts'),
            self::ContractItems => __('Contract details'),
            self::CustomerTags => __('Customer tags'),
        };
    }

    /** Where the admin's cockpit files it. Exhaustive on purpose: a new case must say where it belongs. */
    public function group(): FeatureGroup
    {
        return match ($this) {
            self::Customers, self::ActiveContracts, self::PdfStatements, self::ExportCsv,
            self::AdvancedReports, self::CustomBranding, self::ApiTokens => FeatureGroup::Core,
            self::Members, self::Investors => FeatureGroup::Team,
            self::FlexibleSchedules, self::OpenContracts, self::ContractItems => FeatureGroup::ContractTerms,
            self::CustomerTags => FeatureGroup::Collecting,
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
            self::Members => __('How many people can work in a business: the owner, and the partners, accountants and collectors they invite.'),
            self::FlexibleSchedules => __('Daily, quarterly, half-yearly, yearly or the shop’s own dates, up to 600 instalments, with grace days before an instalment is late.'),
            self::Investors => __('Who funds each contract: the business’s own capital and its partners, with each one’s money and profit as customers pay.'),
            self::OpenContracts => __('A running tab with no schedule: what the customer takes and what they pay, with the balance always right. Any contract can become open.'),
            self::ContractItems => __('What was sold (with serials and IMEIs), its cost, the tax, a discount and the shop’s own contract number, with a list of products to pick from.'),
            self::CustomerTags => __('Tags such as “Shop 2” or “Government staff” that group customers and filter every list.'),
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
            self::Members => __('The Team page and invitations disappear. Everyone already in a business stays in it with their role; nobody is removed.'),
            self::FlexibleSchedules => __('New contracts go back to weekly, two-weekly or monthly plans of up to 120. Contracts already made keep their dates and grace days.'),
            self::Investors => __('No new investors, deposits or withdrawals. Every investor and figure stays readable, and payments keep crediting the investor who funded the contract.'),
            self::OpenContracts => __('No new open contracts, charges or conversions. Open contracts already made keep their lines and balance, and still take payments.'),
            self::ContractItems => __('New contracts go back to a price and a plan. Contracts already made keep their items, numbers, costs and discounts.'),
            self::CustomerTags => __('Tags disappear from the lists and the forms. Customers keep their tags for when it is switched back on.'),
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
            self::AdvancedReports, self::CustomBranding, self::ApiTokens, self::Members, self::FlexibleSchedules, self::Investors, self::OpenContracts, self::ContractItems, self::CustomerTags => [],
        };
    }

    /**
     * Does this feature reach out to a customer (a message, a link, an offer)? The admin's "pause all automation"
     * control switches these off in one action. Declared per feature when it is built; nothing does yet.
     */
    public function touchesCustomers(): bool
    {
        return false;
    }

    /** Part of the "essentials" preset: the small set worth switching on for every workspace first. */
    public function isEssential(): bool
    {
        return in_array($this, [self::Members, self::FlexibleSchedules, self::OpenContracts, self::ContractItems, self::CustomerTags], true);
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
            // Win Plan 6.4: twice the leading rival's ten customers; the customer count is the limit that matters.
            'free' => match ($this) {
                self::Customers => ['enabled' => true, 'limit' => self::FREE_CUSTOMERS],
                self::ActiveContracts => ['enabled' => true, 'limit' => null],
                self::PdfStatements => ['enabled' => true, 'limit' => 5],
                // Investors: the business's own capital only; partners come with Pro (Win Plan PP3).
                self::ApiTokens, self::Members, self::Investors => ['enabled' => true, 'limit' => 1],
                self::ExportCsv, self::AdvancedReports, self::CustomBranding => ['enabled' => false, 'limit' => null],
                self::FlexibleSchedules, self::OpenContracts, self::ContractItems, self::CustomerTags => ['enabled' => true, 'limit' => null],
            },
            // Win Plan 6.4: Pro includes three people (the owner and two more), with no purchase per member.
            'pro' => $this === self::Members ? ['enabled' => true, 'limit' => 3] : ['enabled' => true, 'limit' => null],
            default => null,
        };
    }
}
