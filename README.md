# Chain2Pay for Easy Digital Downloads

Accept card payments in Easy Digital Downloads. Buyers pay by card through an on-ramp provider on the Chain2Pay checkout, and you receive the payment in crypto in your own wallet.

## Requirements

- WordPress 6.2 or newer
- Easy Digital Downloads 3.0 or newer (free or Pro)
- PHP 7.4 or newer with the cURL extension

## Before you start

You need three things:

1. A Chain2Pay account. Create one at https://dashboard.chain2pay.is.
2. A wallet address added and verified in the dashboard. Payments are sent to this wallet.
3. An API key linked to that wallet. Live keys start with `c2p_live_`, test keys start with `c2p_sandbox_`.

Your site must be reachable from the internet over HTTPS. Chain2Pay calls your site to confirm each payment, so a site on `localhost` or behind a password cannot receive confirmations.

## Install

1. Download `Chain2Pay-V1.0.0-EDD.zip` from the [latest release](https://github.com/chain2pay-is/chain2pay-easy-digital-downloads/releases/latest).
2. In WordPress, open **Plugins > Add New Plugin > Upload Plugin**, choose the zip file as it is and click **Install Now**.
3. Click **Activate Plugin**.

## Configure

1. Open **Downloads > Settings > Payments** and click the **Chain2Pay** section.
2. Paste your **Chain2Pay API key** and click **Save Changes**.
3. Go back to the **General** section of the Payments tab. Under **Active Gateways**, tick the Chain2Pay methods you want to offer, for example **Chain2Pay: Hosted checkout: all providers (recommended)**.
4. Optional: choose one of them as **Default Gateway**.
5. Click **Save Changes**.

Each ticked method is one payment choice at checkout. The same API key is used by all of them.

To use the custom provider, tick **Chain2Pay: Custom provider** and fill in **Custom provider slug** in the Chain2Pay section. **Custom provider label** changes its name at checkout.

## Providers

| Provider | Name shown to the buyer | Indicative minimum (USD or EUR) |
|---|---|---|
| Hosted checkout (all providers) | Card Payment | 6 |
| Stripe | Card (Stripe) | 3, USD and EUR only |
| MoonPay | Pay with MoonPay | 20 |
| Revolut | Pay with Revolut | 6 |
| Coinbase | Pay with Coinbase | 10 |
| PayPal | Pay with PayPal | 5 |
| Banxa | Pay with Banxa | 17 |
| Ramp Network | Pay with Ramp | 7 |
| Robinhood (US only) | Pay with Robinhood | 5 |
| Skrill | Pay with Skrill | 22 |
| Alchemy Pay | Pay with Alchemy Pay | 17 |
| Alchemy Pay (local methods) | Local payment methods | 15 |
| Blockchain.com | Pay with Blockchain.com | 15 |
| BTC Direct | Pay with BTC Direct | 33 |
| Coindisco | Pay by card | 23 |
| Cash App (US only) | Pay with Cash App | 20 |
| Crypto | Pay with Crypto | 6 |
| Custom provider | Pay by Card (Chain2Pay) | 5 |

The hosted checkout is the recommended choice: the buyer picks among the providers available for their country and amount. Minimums and availability change over time; the Chain2Pay API always applies the current rules and the plugin shows its answer to the buyer.

The custom provider sends buyers to any other Chain2Pay provider: type its slug, in lowercase, in the plugin settings.

## How a payment is confirmed

1. The buyer chooses a Chain2Pay method. The plugin creates a payment through the Chain2Pay API and sends the buyer to the checkout page.
2. The buyer pays by card with the provider.
3. Chain2Pay calls your site on a URL that the plugin signed for this order and this amount.
4. The plugin asks the Chain2Pay API whether that payment is really paid, and only then marks the order as paid.

A call with a wrong signature, an unknown payment or a lower amount is refused and the order stays unpaid.

## Order statuses

| Situation | Order status |
|---|---|
| Buyer sent to the checkout | Pending |
| Payment confirmed | Complete, with the transaction recorded on the order |
| Payment received for an order that is refunded, revoked or already closed | Unchanged, with an order note |
| Payment received after the order total changed | Unchanged, with an order note |

Every step is written in the order notes.

## Troubleshooting

| Symptom | What to check |
|---|---|
| No Chain2Pay method at checkout | The API key is filled in, at least one provider is enabled, and the order currency and amount are accepted by that provider. |
| "Payment could not be started" | The API key is valid and linked to a verified wallet. The exact answer of the API is written in the log named below. |
| The buyer paid but the order is still unpaid | Your site must answer Chain2Pay's call: no maintenance page, no password protection, no firewall rule that blocks the callback URL. Chain2Pay repeats the call, so the order is updated once the site answers. |
| Stripe is not offered | Stripe takes USD and EUR orders only. |
| The order was paid after you cancelled it | The plugin does not reopen a closed order. The payment is noted on the order so that you can refund the buyer or reopen the order yourself. |

Documentation: https://docs.chain2pay.is. Support: support@chain2pay.is.

Log: **Downloads > Tools > Debug Log** (enable debug mode in **Downloads > Settings > Misc**).

## Uninstall

Untick the Chain2Pay methods under Active Gateways, then deactivate and delete the plugin in **Plugins**. Existing orders keep their notes and transactions.

## License

GPL-3.0-or-later. See the license file in this repository.
