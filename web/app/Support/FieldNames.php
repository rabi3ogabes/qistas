<?php

namespace App\Support;

/**
 * What a mistake calls each field: the name the form shows next to it, in the person's language ("The price field is
 * required.", never "The principal field is required."). Every validator gets these unless it names its fields
 * itself (AppServiceProvider). In English, French and Spanish the name reads in lower case inside the sentence, except
 * a short capitalised name such as VAT or CR.
 */
final class FieldNames
{
    /** @return array<string, string> field => name */
    public static function all(): array
    {
        $names = [
            // People and contact details.
            'name' => __('Name'),
            'business_name' => __('Business name'),
            'email' => __('Email'),
            'phone' => __('Phone'),
            'phone_secondary' => __('Second phone'),
            'address' => __('Address'),
            'job' => __('Job or employer'),
            'national_id' => __('National ID'),
            'relation' => __('How they know the customer'),
            'locale' => __('Language'),
            'photo' => __('Photo'),
            'password' => __('Password'),
            'current_password' => __('Current password'),
            'password_confirmation' => __('Confirm the new password'),
            'code' => __('Code'),
            'recovery_code' => __('Recovery code'),
            'confirm_name' => __('Business name'),

            // Contracts.
            'customer_id' => __('Customer'),
            'principal' => __('Price'),
            'down_payment' => __('Down payment'),
            'markup_type' => __('Markup'),
            'markup_value' => __('Markup'),
            'installment_count' => __('Number of instalments'),
            'count' => __('Number of instalments'),
            'frequency' => __('How often'),
            'grace_days' => __('Grace days'),
            'start_date' => __('Contract date'),
            'first_due_date' => __('First instalment due'),
            'due_date' => __('Date'),
            'title' => __('What was sold'),
            'own_reference' => __('Your contract number'),
            'cost_price' => __('What it cost you'),
            'tax_percent' => __('Tax in the price, %'),
            'discount_type' => __('Discount'),
            'discount_value' => __('Discount'),
            'credit_limit' => __('Credit limit'),
            'opening_balance' => __('What they owe today'),
            'notes' => __('Notes'),
            'investor_id' => __('Investor'),

            // Payments.
            'amount' => __('Amount'),
            'method' => __('How was it paid?'),
            'paid_at' => __('Date received'),
            'apply_to' => __('Pay towards'),
            'note' => __('Note'),
            'reference' => __('Transfer reference'),
            'receipt' => __('Receipt'),
            'rebate_value' => __('Discount'),
            'occurred_on' => __('Date'),

            // The end of the day.
            'counted_cash' => __('Counted cash'),
            'day' => __('Day'),

            // Products, tags, investors.
            'default_price' => __('Price'),
            'cost' => __('What it costs you'),
            'sku' => __('Code'),
            'colour' => __('Colour'),
            'commission_percent' => __('Commission'),
            'commercial_registration' => __('Commercial registration'),
            'opening_capital' => __('Money they start with'),

            // The business profile.
            'name_ar' => __('Name in Arabic'),
            'name_en' => __('Name in English'),
            'cr_number' => __('Commercial registration (CR)'),
            'vat_number' => __('VAT number'),
            'footer' => __('Footer line'),
            'logo' => __('Logo'),

            // Dates and periods.
            'from' => __('From'),
            'to' => __('To'),
            'until' => __('Last day'),
            'reason' => __('Why'),
        ];

        return array_map(self::inSentence(...), $names);
    }

    /** A label as it reads inside a sentence: "Price" is "price" in English, French and Spanish; "VAT number" stays. */
    public static function inSentence(string $name): string
    {
        if (! in_array(app()->getLocale(), ['en', 'fr', 'es'], true)) {
            return $name;
        }

        $second = mb_substr($name, 1, 1);

        return $second !== '' && mb_strtoupper($second) === $second && mb_strtolower($second) !== $second
            ? $name
            : mb_strtolower(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }
}
