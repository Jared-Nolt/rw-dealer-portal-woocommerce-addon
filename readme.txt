=== RW Dealer Portal WooCommerce Addon ===
Contributors: rosewoodmarketing
Requires at least: 6.5
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.3.2
License: GPLv2 or later

Routes WooCommerce order emails to the nearest dealer, plus optional quote checkout, members-only store, product lists and WooCommerce text/price overrides.

== Description ==

Settings: **WooCommerce → Dealer Routing**. Every feature except dealer routing is off until you turn it on.

= Dealer routing (the dealer email) =

When WooCommerce sends its own **New Order** email, this add-on:

1. Geocodes the order address (shipping address, or billing if there is no shipping address).
2. Finds the single nearest dealer within the **Search Radius**.
3. A moment later, in the background, sends that dealer a separate copy of the New Order email with the
   "Message for the Nearest Dealer". Replies go to the customer.
4. Adds an order note naming the dealer. The office's own New Order email also says which dealer got a copy.

The office's New Order email itself is unchanged and still goes to the recipient set in
WooCommerce → Settings → Emails → New Order.

**Each order is emailed to a dealer at most once.** The order claims its dealer email with a database row that
can only be created once, and the send also checks the order before mailing. Resending the New Order email from
the order screen, status changes, checkout retries and background-job retries never send the dealer a second copy.
(This also means "Resend new order notification" does not re-send to the dealer.)

Who counts as a dealer depends on **Dealer Source**:

* **RW Dealer Portal** — published dealer listings with coordinates, a valid address and at least one contact email.
* **Custom Post Type** — any post type; set the latitude/longitude meta keys, or an address meta key to geocode.
* **WordPress Users by Role** — users with the chosen role; coordinates or an address meta key, and an email source.

Use **Test Address** at the bottom of the settings page to see which dealer an address would route to.

= When to turn dealer routing off =

Untick **"Email the nearest dealer about each new order"** when the customers are the dealers themselves,
for example a POP or dealer-only store. Otherwise one dealer's order is copied to a nearby dealer.

As a safety net, **"Never email another dealer about an order placed by a dealer account"** (on by default)
skips routing for orders placed by a portal member, a user linked to a dealer listing, or a user with the dealer
role (User Role source). Guest orders are matched to an account by billing email. Skipped orders get an order note.
Developers can adjust the check with the `rwdpwa_order_placed_by_dealer` filter.

= Quote checkout =

Checkout without online payment: the only payment method is "Submit to office", orders are placed **On hold**,
and the office invoices the customer. Optional: let the office add shipping later, and a PO number field.
Order emails say "quote request" instead of "order". The customer's On hold email and the office's New Order
email are WooCommerce's own emails, so each is sent once by WooCommerce.

= Members-only store =

Only logged-in portal members can see and buy products; others are sent to the portal login. Products are hidden
from search, sitemaps and the Store API. Optional: style the cart/checkout/account pages, pre-fill checkout from
the dealer's details, and a simple My Account menu.

= Product lists =

`[rwdpwa_product_list category="slug"]` shows products with quantity boxes and one "Add to cart" button.
`[rwdpwa_store_links show="store,cart,orders"]` shows store, cart (with count) and orders links.

= WooCommerce overrides =

Replace "order" wording (e.g. with "quote request"), hide prices, and show a label such as "Free" instead of $0.00.

== Setup checklist ==

1. WooCommerce → Dealer Routing: decide whether dealer routing should be on (consumer store) or off (dealer store).
2. With routing on: pick the Dealer Source, set the radius, add a Google Maps API key if RW Dealer Portal's isn't
   used, write the dealer message, then try Test Address.
3. WooCommerce → Settings → Emails → New Order: set the office recipient.
4. Place a test order and check the order notes: you should see one "Order copy emailed to the nearest dealer"
   note, or "Dealer routing skipped" for a dealer's own order.

== Changelog ==

= 1.3.2 =
* New "Never email another dealer about an order placed by a dealer account" setting (on by default): dealer
  routing skips orders placed by portal members, users linked to a dealer, or users with the dealer role.
* Order notes when a dealer is emailed, or when routing is skipped for a dealer's order.
* Help text on the settings page explaining how the dealer email works and when to turn routing off.
* Added this readme with setup instructions.

= 1.3.1 =
* Catalog card style, section backgrounds and panels for the product list; "quote request" wording in quote emails.

= 1.3.0 =
* Quote checkout, members-only store, routing toggle, product list shortcode, store page styling, checkout pre-fill.

= 1.2.0 =
* Optional "Free" label for zero-priced items.
