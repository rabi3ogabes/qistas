<?php

namespace App\Import;

/**
 * What an imported row can say (Win Plan K9), and the column names each field is known by in the five languages, as
 * shops really write them. The first name in each language's list is the one the template uses.
 */
final class Fields
{
    /** @var array<string, array<string, list<string>>> field => language => names */
    public const NAMES = [
        'name' => [
            'en' => ['Name', 'Customer', 'Customer name', 'Client', 'Full name'],
            'ar' => ['الاسم', 'اسم العميل', 'العميل', 'الزبون', 'اسم الزبون'],
            'fr' => ['Nom', 'Client', 'Nom du client'],
            'es' => ['Nombre', 'Cliente', 'Nombre del cliente'],
            'ur' => ['نام', 'گاہک', 'گاہک کا نام'],
        ],
        'phone' => [
            'en' => ['Phone', 'Mobile', 'Phone number', 'Mobile number', 'Tel', 'Telephone', 'WhatsApp'],
            'ar' => ['رقم الجوال', 'الجوال', 'جوال', 'الهاتف', 'رقم الهاتف', 'الموبايل', 'واتساب'],
            'fr' => ['Téléphone', 'Portable', 'Mobile'],
            'es' => ['Teléfono', 'Móvil', 'Celular'],
            'ur' => ['فون نمبر', 'فون', 'موبائل'],
        ],
        'national_id' => [
            'en' => ['ID number', 'ID', 'National ID', 'Iqama'],
            'ar' => ['رقم الهوية', 'الهوية', 'هوية', 'رقم الإقامة', 'الإقامة'],
            'fr' => ['Numéro d’identité', 'Pièce d’identité'],
            'es' => ['Número de identidad', 'DNI', 'Documento'],
            'ur' => ['شناختی نمبر', 'شناختی کارڈ'],
        ],
        'address' => [
            'en' => ['Address'],
            'ar' => ['العنوان'],
            'fr' => ['Adresse'],
            'es' => ['Dirección'],
            'ur' => ['پتہ'],
        ],
        'item' => [
            'en' => ['Item', 'Product', 'Goods', 'What was sold'],
            'ar' => ['السلعة', 'المنتج', 'البضاعة', 'الصنف'],
            'fr' => ['Article', 'Produit'],
            'es' => ['Artículo', 'Producto'],
            'ur' => ['سامان', 'پروڈکٹ'],
        ],
        'total' => [
            'en' => ['Total', 'Amount', 'Price', 'Total amount', 'Contract total'],
            'ar' => ['المبلغ الإجمالي', 'الإجمالي', 'المبلغ', 'السعر', 'قيمة العقد'],
            'fr' => ['Total', 'Montant', 'Prix'],
            'es' => ['Total', 'Importe', 'Precio'],
            'ur' => ['کل رقم', 'رقم', 'قیمت'],
        ],
        'paid' => [
            'en' => ['Paid', 'Amount paid', 'Paid so far'],
            'ar' => ['المدفوع', 'المبلغ المدفوع', 'مدفوع'],
            'fr' => ['Payé', 'Déjà payé'],
            'es' => ['Pagado', 'Ya pagado'],
            'ur' => ['ادا شدہ'],
        ],
        'balance' => [
            'en' => ['Balance', 'Remaining', 'Owed', 'Still owed', 'Outstanding', 'Due'],
            'ar' => ['المتبقي', 'الرصيد', 'المبلغ المتبقي', 'الباقي', 'المستحق'],
            'fr' => ['Solde', 'Reste', 'Reste à payer'],
            'es' => ['Saldo', 'Pendiente', 'Saldo pendiente'],
            'ur' => ['بقایا', 'باقی'],
        ],
        'installments' => [
            'en' => ['Instalments', 'Installments', 'Number of instalments', 'Number of installments', 'Months'],
            'ar' => ['عدد الأقساط', 'الأقساط', 'عدد الأشهر', 'عدد الشهور'],
            'fr' => ['Nombre d’échéances', 'Échéances', 'Mensualités'],
            'es' => ['Número de cuotas', 'Cuotas'],
            'ur' => ['اقساط کی تعداد', 'قسطیں'],
        ],
        'installment_amount' => [
            'en' => ['Instalment amount', 'Installment amount', 'Instalment', 'Installment', 'Monthly'],
            'ar' => ['قيمة القسط', 'القسط', 'القسط الشهري'],
            'fr' => ['Montant de l’échéance', 'Mensualité'],
            'es' => ['Importe de la cuota', 'Cuota'],
            'ur' => ['قسط کی رقم', 'قسط'],
        ],
        'first_due' => [
            'en' => ['First due date', 'First due', 'Next due date', 'Next due', 'Due date'],
            'ar' => ['تاريخ أول قسط', 'تاريخ القسط', 'تاريخ الاستحقاق', 'القسط القادم'],
            'fr' => ['Première échéance', 'Prochaine échéance'],
            'es' => ['Primera cuota', 'Vencimiento'],
            'ur' => ['پہلی قسط', 'اگلی قسط'],
        ],
        'start_date' => [
            'en' => ['Contract date', 'Start date', 'Date'],
            'ar' => ['تاريخ العقد', 'تاريخ البيع', 'التاريخ'],
            'fr' => ['Date du contrat', 'Date'],
            'es' => ['Fecha del contrato', 'Fecha'],
            'ur' => ['معاہدے کی تاریخ', 'تاریخ'],
        ],
        'notes' => [
            'en' => ['Notes', 'Note', 'Remarks'],
            'ar' => ['ملاحظات', 'ملاحظة'],
            'fr' => ['Remarques', 'Notes'],
            'es' => ['Notas', 'Observaciones'],
            'ur' => ['نوٹس', 'تبصرہ'],
        ],
    ];

    /** The amounts, read as money. */
    public const MONEY = ['total', 'paid', 'balance', 'installment_amount'];

    /** The days. */
    public const DATES = ['first_due', 'start_date'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::NAMES);
    }

    /** The field's name for a person choosing columns, in the language being shown. */
    public static function label(string $field): string
    {
        return match ($field) {
            'name' => __('Customer’s name'),
            'phone' => __('Phone'),
            'national_id' => __('ID number'),
            'address' => __('Address'),
            'item' => __('What was sold'),
            'total' => __('Total of the contract'),
            'paid' => __('Paid so far'),
            'balance' => __('Still owed'),
            'installments' => __('Number of instalments'),
            'installment_amount' => __('Instalment amount'),
            'first_due' => __('First instalment’s date'),
            'start_date' => __('Contract date'),
            'notes' => __('Notes'),
            default => $field,
        };
    }

    /** The column name a template in [$language] uses for [$field]. */
    public static function header(string $field, string $language): string
    {
        return self::NAMES[$field][$language][0] ?? self::NAMES[$field]['en'][0];
    }
}
