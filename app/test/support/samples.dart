import 'package:qistas/data/models.dart';

/// JSON as the API writes it, for tests. Amounts are two-decimal strings.

Map<String, dynamic> entitlementsJson({int customersUsed = 3, String plan = 'free'}) {
  final pro = plan == 'pro';

  Map<String, dynamic> counted(int? limit, int used) => {
        'type': 'limit', 'enabled': true, 'limit': limit, 'used': used,
        'remaining': limit == null ? null : (limit - used).clamp(0, limit), 'unlimited': limit == null,
      };

  Map<String, dynamic> toggle(bool on) => {'type': 'toggle', 'enabled': on, 'limit': null, 'used': null, 'remaining': null, 'unlimited': false};

  return {
    'customers': counted(pro ? null : 5, customersUsed),
    'active_contracts': counted(pro ? null : 5, 1),
    'pdf_statements': {'type': 'quota', 'enabled': true, 'limit': pro ? null : 3, 'used': 0, 'remaining': pro ? null : 3, 'unlimited': pro},
    'export_csv': toggle(pro),
    'advanced_reports': toggle(pro),
    'custom_branding': toggle(pro),
    'api_tokens': counted(pro ? null : 1, 0),
  };
}

Map<String, dynamic> accountJson({String role = 'owner', int customersUsed = 3, String plan = 'free'}) => {
      'user': {'id': 'u1', 'name': 'Layla Haddad', 'email': 'layla@example.com', 'email_verified': true, 'locale': 'en', 'two_factor': false},
      'tenant': {'id': 't1', 'name': 'Al-Fares Electronics', 'country': 'SA', 'currency': 'SAR', 'role': role},
      'plan': {'key': plan, 'name': plan == 'pro' ? 'Pro' : 'Free'},
      'entitlements': entitlementsJson(customersUsed: customersUsed, plan: plan),
    };

Map<String, dynamic> sessionJson() => {
      'token': 'qst_new-token',
      'token_type': 'Bearer',
      'expires_at': '2026-11-06T12:00:00Z',
      ...accountJson(),
    };

Account sampleAccount({String role = 'owner', int customersUsed = 3, String plan = 'free'}) =>
    Account.fromJson(accountJson(role: role, customersUsed: customersUsed, plan: plan));

Account sampleAccountFromJson(Map<String, dynamic> json) => Account.fromJson(json);

Map<String, dynamic> customerJson({String id = 'c1', String name = 'Ahmad Salem'}) => {
      'id': id, 'name': name, 'phone': '+966501234567', 'phone_secondary': null, 'email': 'ahmad@example.com',
      'national_id': '••••••890', 'address': 'Riyadh', 'notes': null, 'created_at': '2026-10-01T09:00:00Z',
      'owed': '200.00', 'running_contracts': 1,
    };

Map<String, dynamic> pageJson(List<Map<String, dynamic>> data, {int page = 1, int last = 3, int total = 12}) => {
      'data': data,
      'links': {'first': null, 'last': null, 'prev': null, 'next': null},
      'meta': {'current_page': page, 'last_page': last, 'per_page': 20, 'total': total},
    };

Map<String, dynamic> customerPageJson() => pageJson([customerJson()], page: 2);

Map<String, dynamic> installmentJson(int n, {String state = 'upcoming', String paid = '0.00', String status = 'pending'}) => {
      'id': 'i$n', 'number': n, 'due_date': '2026-${(10 + n).toString().padLeft(2, '0')}-07', 'amount': '275.00',
      'paid_amount': paid, 'remaining': paid == '275.00' ? '0.00' : '275.00', 'status': status, 'state': state, 'paid_at': null,
    };

Map<String, dynamic> lineJson({String type = 'payment', String amount = '275.00', bool voided = false}) => {
      'id': 't1', 'type': type, 'method': 'cash', 'amount': amount, 'paid_at': '2026-10-07T12:00:00Z', 'note': null, 'voided': voided,
      'reverses_transaction_id': type == 'reversal' ? 't0' : null,
      'created_by': {'id': 'u1', 'name': 'Layla Haddad'}, 'customer': {'id': 'c1', 'name': 'Ahmad Salem'},
      'contract': {'id': 'k1', 'reference': 'C-0007', 'status': 'active', 'owed': '825.00'},
    };

Map<String, dynamic> contractJson({String status = 'active', String state = 'active'}) => {
      'id': 'k1', 'reference': 'C-0007', 'number': 7, 'type': 'scheduled', 'status': status, 'state': state,
      'principal': '1200.00', 'down_payment': '200.00', 'financed': '1000.00', 'markup_type': 'percent', 'markup_value': '10.00',
      'markup_amount': '100.00', 'total': '1100.00', 'installment_count': 4, 'frequency': 'monthly', 'start_date': '2026-10-07',
      'first_due_date': '2026-11-07', 'notes': null, 'created_at': '2026-10-07T12:00:00Z', 'settled_at': null, 'cancelled_at': null,
      'customer': {'id': 'c1', 'name': 'Ahmad Salem'}, 'owed': '825.00', 'paid': '275.00',
      'next_installment': {'number': 2, 'due_date': '2026-12-07', 'remaining': '275.00'},
      'installments': [
        installmentJson(1, state: 'paid', paid: '275.00', status: 'paid'),
        installmentJson(2),
        installmentJson(3),
        installmentJson(4),
      ],
      'transactions': [lineJson()],
    };

Map<String, dynamic> contractPageJson() => pageJson([contractJson()], page: 1, last: 1, total: 1);

Map<String, dynamic> dashboardJson() => {
      'currency': 'SAR', 'outstanding': '5000.00', 'overdue': '300.00', 'collected_this_month': '1200.00', 'active_customers': 4,
      'collection_rate': '62.5',
      'due_today': [
        {'installment_id': 'i1', 'contract_id': 'k1', 'contract_reference': 'C-0007', 'customer_id': 'c1', 'customer_name': 'Ahmad Salem', 'amount_due': '275.00', 'due_date': '2026-10-07'},
      ],
    };

List<Map<String, dynamic>> plansJson() => [
      {
        'key': 'free', 'name': 'Free', 'description': null, 'is_free': true, 'currency': 'USD', 'monthly_price': '0.00',
        'yearly_price': '0.00', 'yearly_saving_percent': null,
        'features': {
          'customers': {'type': 'limit', 'label': 'Customers', 'enabled': true, 'limit': 5, 'summary': 'Up to 5 customers'},
          'export_csv': {'type': 'toggle', 'label': 'CSV export', 'enabled': false, 'limit': null, 'summary': 'Not included'},
        },
      },
      {
        'key': 'pro', 'name': 'Pro', 'description': 'Everything, unlimited', 'is_free': false, 'currency': 'USD', 'monthly_price': '12.00',
        'yearly_price': '120.00', 'yearly_saving_percent': 17,
        'features': {
          'customers': {'type': 'limit', 'label': 'Customers', 'enabled': true, 'limit': null, 'summary': 'Unlimited customers'},
          'export_csv': {'type': 'toggle', 'label': 'CSV export', 'enabled': true, 'limit': null, 'summary': 'CSV export'},
        },
      },
    ];
