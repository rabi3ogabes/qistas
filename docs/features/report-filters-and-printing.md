# Report filters and printing (Win Plan RP11, RP10)

The payments report and the customer statement can be narrowed to one investor's contracts or to the contracts that
sold one product, for a period. Any document can be printed straight from the phone, and a receipt prints at its till
roll's width.

## Filters

- **Payments report** (`GET /reports/transactions.pdf`, the web's Payments page, the app's Payments screen): this month
  so far unless `from` and `to` are given; `investor` keeps the payments on contracts that investor funds, `product`
  the payments on contracts that sold that product. The lines, the total and the total for each way of paying all
  follow the filter. Not for collectors.
- **Customer statement** (`GET /customers/{customer}/statement.pdf`): the same `investor` and `product`, so the
  contracts, what is owed and late, and the account lines are only those contracts'.
- Both documents name the filter under their title ("Contracts funded by Saeed", "Contracts that sold Galaxy"), so a
  page printed for one investor is never read as the whole business's.
- An investor or a product of another workspace is refused (422).

**Web**: the document's choices panel offers an Investor and a Product list beside the period. It lists only choices
that can return something: investors who fund a contract and products a contract sold (on a customer's page, only
theirs). Investors are listed only to those who see them, and only while the Investors feature is on.

**App**: the document sheet shows the period (From and To, in Hijri too when the workspace shows it) and the Investor
and Product lists. The payments report starts on this month; a statement covers the whole account until a day is
chosen, and a chosen day can be cleared. The Investor list appears when there is more than one investor.

## Printing

The app's document sheet has **Print** beside **Share**. Print fetches the document with the choices made and hands it
to Android's print service (the `printing` package), which reaches every printer whose app registers with it, Bluetooth
till-roll printers included. The page size follows the paper chosen: A4, A5, or a roll of 80 mm or 58 mm for a receipt.
A phone without a print service says so and suggests sharing to the printer's app instead.
