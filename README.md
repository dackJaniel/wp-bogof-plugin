# BOGOF - Buy One Get One Free WordPress Plugin

## ⚠️ IMPORTANT NOTICES

**🚫 NO ACTIVE MAINTENANCE**

This plugin is not actively maintained or developed. Use at your own risk.

**⚠️ FRONTEND NOT WORKING**

The frontend (`bogof-plugin-front.php`) is currently not working. Only use the main version (`bogof-plugin.php`).

**🤖 AI-GENERATED CODE**

Parts of this code were generated with AI assistance. No warranty is provided for correctness or functionality. Test the plugin thoroughly before using it in a production environment.

---

## 📝 Description

BOGOF (Buy One Get One Free) is a WordPress/WooCommerce plugin that automatically adds a free product to the cart when certain conditions are met. The plugin supports:

- **Campaign-based system**: Multiple independent BOGOF campaigns in parallel
- **Coupon integration**: Free product is only added with valid coupon code
- **Product conditions**: Define which products must be in the cart
- **Time control**: Start date and end date for campaigns
- **Variation support**: Support for variable WooCommerce products
- **Exclusion rules**: Exclude specific product variations

---

## 🔧 Installation

1. Upload the plugin files to the `/wp-content/plugins/bogof-plugin/` directory
2. Activate the plugin through the WordPress admin panel under "Plugins"
3. Configure your campaigns in the `bogof-campaigns.php` file

### Upload per FileZilla (SFTP) & Konfiguration

1. Stelle sicher, dass du die SFTP-Zugangsdaten hast (Host, Port, Benutzername, Passwort oder SSH-Key) sowie den Pfad zur WordPress-Installation.
2. FileZilla öffnen:
   - `Datei` -> `Servermanager` -> `Neuer Server`
   - Protokoll: `SFTP - SSH File Transfer Protocol`
   - Host: z.B. `example.com`, Port: meist `22`
   - Anmeldetyp: `Normal` (Passwort) oder `Schlüsseldatei` (Key)
3. Verbinden und im Remote-Verzeichnis zum WordPress-Ordner navigieren.
   - Typische Pfade: `public_html/`, `httpdocs/` oder `www/`
   - Dort muss es ein `wp-content/` geben.
4. Auf dem Server in `wp-content/plugins/` wechseln.
5. Den kompletten Plugin-Ordner hochladen:
   - Ziel auf dem Server: `wp-content/plugins/bogof-plugin/`
   - Wichtig: Der Ordnername sollte `bogof-plugin` sein (also nicht z.B. `wp-bogof-plugin-main`).
6. In WordPress Admin -> `Plugins` das Plugin aktivieren.
7. Kampagnen konfigurieren:
   - `wp-content/plugins/bogof-plugin/bogof-campaigns.php` bearbeiten (lokal anpassen und per FileZilla wieder hochladen oder direkt per Editor in FileZilla).
   - Product-IDs/Variation-IDs aus WooCommerce verwenden (Produkte -> ID in der URL/Listenansicht).

Hinweise:
- Nutze nur `bogof-plugin.php` (die Datei `bogof-plugin-front.php` ist laut "Known Issues" nicht funktionsfähig).
- Falls ein Cache/Opcode-Cache aktiv ist, ggf. Cache leeren (z.B. WP-Cache-Plugin, Server-Cache), damit Änderungen sofort greifen.
- Datei-/Ordnerrechte: Falls Upload fehlschlägt, prüfe Schreibrechte im `plugins/`-Ordner.

---

## ⚙️ Configuration

### Configuring Campaigns

Edit the `bogof-campaigns.php` file and define your campaigns:

```php
return [
    [
        "name" => "Spring Sale",                   // Campaign name
        "coupon_codes" => ["SPRING2025"],          // Valid coupon codes
        "required_products" => [123, 456],         // Product IDs that must be in cart
        "excluded_variations" => [789],            // Excluded variation IDs
        "free_product_id" => 999,                  // Free product ID
        "free_variation_id" => 0,                  // 0 = first available variation
        "start_date" => "2025-01-01",              // Start date (YYYY-MM-DD)
        "end_date" => "2025-12-31",                // End date or null
        "max_quantity" => 1,                       // Maximum free product quantity
        "active" => true,                          // Campaign enabled
    ],
];
```

### Parameter Reference

| Parameter | Type | Description |
|-----------|------|-------------|
| `name` | String | Campaign name (for logs and debugging) |
| `coupon_codes` | Array | List of valid coupon codes |
| `required_products` | Array | Product IDs - at least one must be in cart |
| `excluded_variations` | Array | Variation IDs excluded from the promotion |
| `free_product_id` | Integer | ID of the product to add for free |
| `free_variation_id` | Integer | Specific variation ID or 0 for first available |
| `start_date` | String/null | Start date in YYYY-MM-DD format or null |
| `end_date` | String/null | End date in YYYY-MM-DD format or null |
| `max_quantity` | Integer | Maximum quantity of free product |
| `active` | Boolean | true = campaign active, false = inactive |

---

## 🏗️ Code Structure

### Main Files

- **`bogof-plugin.php`** - Main plugin file with campaign management and WooCommerce hooks (✅ FUNCTIONAL)
- **`bogof-campaigns.php`** - Campaign configuration file
- **`bogof-plugin-front.php`** - Alternative frontend implementation (❌ NOT FUNCTIONAL)
- **`wp-blog-header.php`** - Standard WordPress header loader

### Important Classes

#### `BOGOF_Campaign`
Represents a single BOGOF campaign with all parameters and validation methods:
- `is_valid()` - Checks if the campaign is valid for the current date
- `has_valid_coupon($applied_coupons)` - Checks if a valid coupon has been applied

#### `BOGOF_Campaign_Manager`
Singleton class to manage all campaigns:
- `load_campaigns()` - Loads campaigns from configuration file
- `find_matching_campaign($applied_coupons)` - Finds matching campaign based on coupons
- `has_required_products($campaign, $cart_items)` - Checks product requirements
- `get_active_campaigns()` - Returns all active campaigns

### Important Functions

- **`add_free_product_with_coupon()`** - Adds free product based on campaign conditions
- **`bogof_check_coupon()`** - Validates coupons and prevents misuse
- **`set_free_product_price()`** - Sets the price of the free product to 0
- **`limit_free_product_quantity()`** - Limits the quantity of the free product
- **`remove_free_product_if_requirements_not_met()`** - Removes free product when conditions are no longer met

### WooCommerce Hooks

The plugin uses the following WooCommerce hooks:

```php
add_action('woocommerce_check_cart_items', 'add_free_product_with_coupon');
add_filter('woocommerce_coupon_is_valid', 'bogof_check_coupon', 10, 2);
add_action('woocommerce_before_calculate_totals', 'set_free_product_price');
add_filter('woocommerce_add_to_cart_validation', 'limit_free_product_quantity', 10, 3);
add_filter('woocommerce_cart_item_quantity', 'disable_free_product_quantity_changes', 10, 3);
add_filter('woocommerce_update_cart_validation', 'validate_cart_item_quantity', 10, 4);
add_action('woocommerce_before_calculate_totals', 'remove_free_product_if_requirements_not_met');
```

---

## 🐛 Debugging

Set `$bogof_debug = true;` in `bogof-plugin.php` (line 17) to enable debug messages:

```php
$bogof_debug = true; // Enable debug mode
```

Debug messages will appear as WooCommerce notices in the frontend.

---

## 📋 Requirements

- **WordPress**: 5.0 or higher
- **WooCommerce**: 3.0 or higher
- **PHP**: 7.0 or higher

---

## 📜 License

**Free to use for everybody**

This plugin is freely available and can be used by anyone without restrictions. There is no guarantee or warranty. Use at your own risk.

---

## ⚠️ Disclaimer

**NO LIABILITY FOR ERRORS**

The author assumes no liability for errors, damages, or problems arising from the use of this plugin. As parts of the code were generated with AI assistance, it is strongly recommended to thoroughly test the plugin in a test environment before using it in a live environment.

---

## 👤 Author

Daniel Hilmer

---

## 📝 Changelog

### Version 1.0
- Initial release
- Campaign-based system
- Support for multiple parallel campaigns
- Coupon integration
- Time-based campaign control
- Support for variable products
- Exclusion rules for variations

---

## 🔍 Known Issues

1. **Frontend version not working** - Only use `bogof-plugin.php`
2. **No active maintenance** - No updates or bugfixes planned
3. **AI-generated code** - Potential undiscovered bugs possible
