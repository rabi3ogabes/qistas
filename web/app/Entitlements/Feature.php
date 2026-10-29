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
    case InstalmentAlerts = 'instalment_alerts';
    case DailyDigest = 'daily_digest';
    case EarlySettlement = 'early_settlement';
    case Reschedule = 'reschedule';
    case PaymentTargeting = 'payment_targeting';
    case StoreBilling = 'store_billing';
    case ManualBilling = 'manual_billing';
    case CardBilling = 'card_billing';
    case LateFees = 'late_fees';
    case HijriDates = 'hijri_dates';
    case Guarantors = 'guarantors';
    case PromissoryNote = 'promissory_note';
    case Import = 'import';
    case AiReading = 'ai_reading';
    case HomeWidget = 'home_widget';
    case DayClose = 'day_close';
    case PaydayLearning = 'payday_learning';

    /** How many customers the free plan keeps (tests and copy read this rather than repeating the number). */
    public const FREE_CUSTOMERS = 20;

    public function type(): FeatureType
    {
        return match ($this) {
            self::Customers, self::ActiveContracts, self::ApiTokens, self::Members, self::Investors, self::Import => FeatureType::Limit,
            self::PdfStatements, self::AiReading => FeatureType::Quota,
            self::ExportCsv, self::AdvancedReports, self::CustomBranding, self::FlexibleSchedules, self::OpenContracts, self::ContractItems, self::CustomerTags,
            self::InstalmentAlerts, self::DailyDigest, self::EarlySettlement, self::Reschedule, self::PaymentTargeting,
            self::StoreBilling, self::ManualBilling, self::CardBilling, self::LateFees, self::HijriDates, self::Guarantors, self::PromissoryNote, self::HomeWidget, self::DayClose, self::PaydayLearning => FeatureType::Toggle,
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
            self::InstalmentAlerts => __('Instalment alerts and remind-all'),
            self::DailyDigest => __('Morning summary'),
            self::EarlySettlement => __('Early settlement'),
            self::Reschedule => __('Change the payment plan'),
            self::PaymentTargeting => __('Choose what a payment covers'),
            self::StoreBilling => __('Pro through Google Play'),
            self::ManualBilling => __('Pro by bank transfer'),
            self::CardBilling => __('Pro by card'),
            self::LateFees => __('Late fees'),
            self::HijriDates => __('Hijri dates'),
            self::Guarantors => __('Guarantors, photos and signatures'),
            self::PromissoryNote => __('Promissory note'),
            self::Import => __('Import from Excel'),
            self::AiReading => __('Reading notebook photos and PDFs'),
            self::HomeWidget => __('Home-screen widget'),
            self::DayClose => __('End-of-day close'),
            self::PaydayLearning => __('Payday learning'),
        };
    }

    /** Where the admin's cockpit files it. Exhaustive on purpose: a new case must say where it belongs. */
    public function group(): FeatureGroup
    {
        return match ($this) {
            self::Customers, self::ActiveContracts, self::PdfStatements, self::ExportCsv,
            self::AdvancedReports, self::CustomBranding, self::ApiTokens => FeatureGroup::Core,
            self::Members, self::Investors, self::Import, self::AiReading, self::DayClose => FeatureGroup::Team,
            self::FlexibleSchedules, self::OpenContracts, self::ContractItems, self::EarlySettlement, self::Reschedule, self::PaymentTargeting => FeatureGroup::ContractTerms,
            self::CustomerTags, self::InstalmentAlerts, self::DailyDigest, self::HomeWidget, self::PaydayLearning => FeatureGroup::Collecting,
            self::StoreBilling, self::ManualBilling, self::CardBilling => FeatureGroup::Membership,
            self::LateFees => FeatureGroup::LateCustomers,
            self::HijriDates, self::Guarantors, self::PromissoryNote => FeatureGroup::Documents,
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
            self::InstalmentAlerts => __('An alert on each instalment’s due date and once it is late, a checklist that reminds everyone due today on WhatsApp, and the business’s own reminder wording.'),
            self::DailyDigest => __('Each morning, at the time each person chooses, how many pay today, how many are late and how much.'),
            self::EarlySettlement => __('Settle a contract early with a discount on the profit not yet earned; the quote shows the payoff before anything is written.'),
            self::Reschedule => __('A new amount or count for what is left, a skipped month or a moved date, with the reason kept. Paid instalments never move.'),
            self::PaymentTargeting => __('Pick the instalment a payment covers, and take one payment across all of a customer’s contracts.'),
            self::StoreBilling => __('Owners subscribe to a paid plan in the Android app through Google Play; the purchase is checked with Google before the plan turns on.'),
            self::ManualBilling => __('Owners pay by bank transfer and upload the receipt on the website; the Qistas team approves it, or it turns on by itself when automatic approval is on.'),
            self::CardBilling => __('Owners subscribe to a paid plan on the website by card, Apple Pay or Google Pay, through Stripe; the plan follows Stripe’s renewals, failures and cancellations.'),
            self::HijriDates => __('Umm al-Qura dates beside the Gregorian ones, or first, on every page and document, and dates typed or picked in Hijri. What is stored stays Gregorian.'),
            self::Guarantors => __('Up to three guarantors on a contract with their ID number encrypted and shown masked; photos of the ID, the signed contract and the invoice, opened only through links that expire; and the customer’s signature, on the owner’s screen or through a one-time-code link on their own phone.'),
            self::AiReading => __('A photo of a notebook page, or the PDF reports another app exports, read by Claude into the same rows an Excel file gives, then the same trial run. Counted in pages a month; needs the Anthropic key saved by the Qistas team.'),
            self::Import => __('Customers and their balances brought in from an Excel or CSV file: the columns recognised in five languages, a trial run that names every problem by row and column, then one confirm. The plan sets how many rows one file may have.'),
            self::PromissoryNote => __('A promissory note for what the customer owes, the amount in figures and in words, ready to sign and checked through its QR code. Its wording is a draft until a lawyer approves it.'),
            self::LateFees => __('A fee for each period an instalment is late, after the grace days: an amount or a percent, once, daily, weekly or monthly, with caps, and optionally for charity. Each fee is its own line, paid or waived with a reason.'),
            self::HomeWidget => __('Today’s money on the phone’s home screen: how many pay today, how many are late and how much, with the next few instalments; counts and amounts only, unless the owner allows customers’ names.'),
            self::DayClose => __('A short evening ritual: the cash counted is compared with what was recorded for the day, and a signed-off summary is kept.'),
            self::PaydayLearning => __('Notices the day of the month each customer really pays and suggests dates and nudges around it, never changing anything by itself.'),
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
            self::InstalmentAlerts => __('No more instalment alerts, and the remind-all checklist closes. Choices and wording are kept for when it is switched back on.'),
            self::DailyDigest => __('No more morning summaries. Each person’s chosen time is kept.'),
            self::EarlySettlement => __('Early settlement is no longer offered. Contracts already settled early stay as they are.'),
            self::Reschedule => __('Plans can no longer be changed. Changes already made stay, with their history.'),
            self::PaymentTargeting => __('Payments go to the oldest instalment again, one contract at a time. Payments already made stay as they are.'),
            self::StoreBilling => __('The app stops offering subscriptions. Plans already bought keep running until the store ends them, and its messages are still applied.'),
            self::ManualBilling => __('The website stops taking bank transfer requests. Requests already sent can still be approved or rejected, and plans already given keep their end date.'),
            self::CardBilling => __('The website stops taking card subscriptions. Subscriptions already running keep renewing in Stripe and their messages are still applied.'),
            self::HijriDates => __('Dates are shown in the Gregorian calendar alone again. Each workspace’s choice is kept for when it is switched back on.'),
            self::Guarantors => __('No new guarantors, photos or signatures can be added. What is already on contracts stays, and can still be opened and removed.'),
            self::AiReading => __('Photos and PDFs can no longer be read. Excel and CSV files still can, and everything already brought in stays.'),
            self::Import => __('Files can no longer be imported. Everything already imported stays, and trial runs not yet confirmed are kept.'),
            self::PromissoryNote => __('The promissory note is no longer offered. Notes already printed stay valid on their verification page.'),
            self::LateFees => __('No new fees are charged. Fees already charged stay on their contracts and can still be paid or waived; each contract keeps the policy it was made with.'),
            self::HomeWidget => __('An installed widget shows “Open Qistas” and stops reading. Nothing is deleted.'),
            self::DayClose => __('No new closes and no reminders, and the page and the API are hidden. Closed days and their summaries are kept, and come back when it is switched on again.'),
            self::PaydayLearning => __('Suggestions disappear and patterns stop updating. Patterns already worked out are kept for when it is switched back on.'),
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
            self::AdvancedReports, self::CustomBranding, self::ApiTokens, self::Members, self::FlexibleSchedules, self::Investors, self::OpenContracts, self::ContractItems, self::CustomerTags,
            self::InstalmentAlerts, self::DailyDigest, self::EarlySettlement, self::Reschedule, self::PaymentTargeting,
            self::StoreBilling, self::ManualBilling, self::CardBilling, self::LateFees, self::HijriDates, self::Guarantors, self::PromissoryNote, self::Import, self::HomeWidget, self::DayClose, self::PaydayLearning => [],
            self::AiReading => [self::Import],
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
        return in_array($this, [self::Members, self::FlexibleSchedules, self::OpenContracts, self::ContractItems, self::CustomerTags, self::InstalmentAlerts, self::DailyDigest, self::EarlySettlement, self::Reschedule, self::PaymentTargeting], true);
    }

    /** 'workspace' features are assigned to plans; 'platform' ones (membership billing) are only switched. */
    public function scope(): string
    {
        return $this->group() === FeatureGroup::Membership ? 'platform' : 'workspace';
    }

    /**
     * The features a plan is made of, for price lists and comparisons: everything but the platform's own switches.
     *
     * @return list<Feature>
     */
    public static function planFeatures(): array
    {
        return array_values(array_filter(self::cases(), fn (Feature $feature) => $feature->scope() === 'workspace'));
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
                // Win Plan 6.4: Free imports up to 100 rows at a time; Pro has no limit.
                self::Import => ['enabled' => true, 'limit' => 100],
                // Win Plan 6.4: reading photos and PDFs with AI is part of Pro.
                self::AiReading => ['enabled' => false, 'limit' => null],
                self::ExportCsv, self::AdvancedReports, self::CustomBranding => ['enabled' => false, 'limit' => null],
                // Daily-habit spec D15: the widget and the day close are Free; learning the real payday is Pro.
                self::PaydayLearning => ['enabled' => false, 'limit' => null],
                self::FlexibleSchedules, self::OpenContracts, self::ContractItems, self::CustomerTags,
                self::InstalmentAlerts, self::DailyDigest, self::EarlySettlement, self::Reschedule, self::PaymentTargeting, self::LateFees, self::HijriDates, self::Guarantors, self::PromissoryNote, self::HomeWidget, self::DayClose => ['enabled' => true, 'limit' => null],
                // The platform's own switches are not part of any plan: only the switch decides.
                self::StoreBilling, self::ManualBilling, self::CardBilling => ['enabled' => true, 'limit' => null],
            },
            // Win Plan 6.4: Pro includes three people (the owner and two more), with no purchase per member.
            'pro' => match ($this) {
                self::Members => ['enabled' => true, 'limit' => 3],
                // Win Plan 6.4: 50 AI-read pages a month.
                self::AiReading => ['enabled' => true, 'limit' => 50],
                default => ['enabled' => true, 'limit' => null],
            },
            default => $this->scope() === 'platform' ? ['enabled' => true, 'limit' => null] : null,
        };
    }
}
