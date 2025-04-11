<?php
/*
Plugin Name: BOGOF - Buy One Get One Free
Description: Fügt automatisch ein Gratisprodukt zum Warenkorb hinzu, wenn bestimmte Bedingungen erfüllt sind
Version: 1.0
Author: Daniel Hilmer
*/

// Sicherheitscheck
if (!defined('ABSPATH')) {
    exit;
}

// ===== KONFIGURATION =====

// Liste der Produkt-IDs, die im Warenkorb sein müssen (mindestens eines davon)
// HINWEIS: Für variable Produkte kannst du entweder die Haupt-Produkt-ID oder bestimmte Variations-IDs angeben
$bogof_required_products = array(1, 2, 3); // Ersetze mit deinen Produkt-IDs

// Liste der Variations-IDs, die von der Aktion ausgeschlossen werden sollen
// Diese werden auch dann ausgeschlossen, wenn das Hauptprodukt erlaubt ist
$bogof_excluded_variations = array(3, 4, 5); // Ersetze mit deinen Variations-IDs

// ID des Gratisprodukts, das hinzugefügt werden soll
$bogof_free_product_id = 7; // Ersetze mit der ID deines Gratisprodukts

// Bei variablen Produkten: ID der spezifischen Variation (optional, 0 = erste verfügbare Variation)
$bogof_free_variation_id = 0; // 0 = erste verfügbare Variation oder setze eine spezifische Variations-ID

// Liste der Coupon-Codes, die das Gratisprodukt auslösen
$bogof_coupon_codes = array('COUPON', 'BOGOF', 'GRATIS'); // Ersetze mit deinen Coupon-Codes

// Gültigkeitszeitraum für die Aktion (YYYY-MM-DD Format)
$bogof_start_date = '2025-04-12'; // Startdatum
$bogof_end_date = '2025-04-21';   // Enddatum

// Debug-Modus (true/false)
$bogof_debug = false; // Auf false setzen für Produktivumgebung

// Maximale Anzahl des Gratisprodukts, die bestellt werden kann
$bogof_max_quantity = 1;

// ===== FUNKTIONEN =====

/**
 * Debug-Funktion für BOGOF
 */
function bogof_debug($message)
{
    global $bogof_debug;
    if ($bogof_debug && current_user_can('manage_options')) {
        wc_add_notice('[DEBUG] ' . $message, 'notice');
    }
}

/**
 * Prüft, ob ein Produkt eine der ausgeschlossenen Variations-IDs hat
 */
function bogof_is_excluded_variation($product_id, $variation_id = 0)
{
    global $bogof_excluded_variations;

    // Wenn keine Variations-IDs zum Ausschließen definiert sind, überspringen
    if (empty($bogof_excluded_variations)) {
        return false;
    }

    // Wenn es eine Variation ist, direkt prüfen
    if ($variation_id > 0 && in_array($variation_id, $bogof_excluded_variations)) {
        bogof_debug("Variation mit ID $variation_id ist ausgeschlossen");
        return true;
    }

    return false;
}

/**
 * Prüft, ob ein bestimmtes Produkt oder eine seiner Variationen im Warenkorb ist
 */
function bogof_is_product_in_cart($product_id)
{
    global $bogof_excluded_variations;

    foreach (WC()->cart->get_cart() as $cart_item) {
        $variation_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;

        // Prüfen, ob die Variation ausgeschlossen ist
        if (bogof_is_excluded_variation($cart_item['product_id'], $variation_id)) {
            continue; // Diese Variation überspringen
        }

        // Prüfe direkte Übereinstimmung mit Produkt-ID (einfaches Produkt oder Hauptprodukt)
        if ($cart_item['product_id'] == $product_id) {
            return true;
        }

        // Bei variablen Produkten: Prüfe, ob das Elternprodukt übereinstimmt
        if ($variation_id > 0) {
            $product = wc_get_product($variation_id);
            if ($product && $product->get_parent_id() == $product_id) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Fügt automatisch ein Gratisprodukt zum Warenkorb hinzu, wenn ein bestimmter Coupon
 * verwendet wird und bestimmte Produkte im Warenkorb liegen.
 */
function add_free_product_with_coupon()
{
    global $bogof_required_products, $bogof_free_product_id, $bogof_coupon_codes,
        $bogof_start_date, $bogof_end_date, $bogof_free_variation_id, $bogof_excluded_variations;

    // Cache-Key für die aktuelle Operation
    static $already_run = false;

    // Nur einmal pro Seitenaufruf ausführen
    if ($already_run) {
        return;
    }

    $already_run = true;

    // Nur ausführen, wenn wir uns im Warenkorb oder Checkout befinden
    if (!is_cart() && !is_checkout() && !wp_doing_ajax()) {
        return;
    }

    bogof_debug("BOGOF Funktion gestartet");

    // Prüfe, ob das aktuelle Datum im gültigen Zeitraum liegt
    $current_date = date('Y-m-d');
    if ($current_date < $bogof_start_date || $current_date > $bogof_end_date) {
        bogof_debug("Datum ungültig: Heute ist $current_date, gültig von $bogof_start_date bis $bogof_end_date");
        return; // Außerhalb des gültigen Zeitraums
    }

    // Prüfe, ob der Coupon angewendet wurde
    $applied_coupons = WC()->cart->get_applied_coupons();
    if (empty($applied_coupons) || empty(array_intersect(array_map('strtolower', $bogof_coupon_codes), array_map('strtolower', $applied_coupons)))) {
        bogof_debug("Keiner der Coupons " . implode(", ", $bogof_coupon_codes) . " wurde angewendet");
        return;
    }
    bogof_debug("Einer der Coupons " . implode(", ", $bogof_coupon_codes) . " ist aktiv");

    // Optimierte Suche nach erforderlichen Produkten
    $found_required_product = false;
    $found_products = array();
    $cart_items = WC()->cart->get_cart();

    // Hole erst alle IDs im Warenkorb (schneller)
    $product_ids_in_cart = [];
    $variation_ids_in_cart = [];
    $variation_parents = [];

    foreach ($cart_items as $cart_item) {
        $product_id = $cart_item['product_id'];
        $variation_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;

        // Wenn die Variation ausgeschlossen ist, überspringen
        if (bogof_is_excluded_variation($product_id, $variation_id)) {
            bogof_debug("Überspringe ausgeschlossene Variation: $variation_id");
            continue;
        }

        // Sammle Produkt-IDs
        $product_ids_in_cart[] = $product_id;

        if ($variation_id > 0) {
            $variation_ids_in_cart[] = $variation_id;

            // Cache parent IDs for variations
            if (!isset($variation_parents[$variation_id])) {
                $product = wc_get_product($variation_id);
                if ($product) {
                    $variation_parents[$variation_id] = $product->get_parent_id();
                }
            }
        }
    }

    bogof_debug("Produkte im Warenkorb: " . implode(", ", $product_ids_in_cart));
    if (!empty($bogof_excluded_variations)) {
        bogof_debug("Ausgeschlossene Variations-IDs: " . implode(", ", $bogof_excluded_variations));
    }

    // Prüfe, ob eines unserer erforderlichen Produkte im Warenkorb ist
    foreach ($bogof_required_products as $required_product_id) {
        // Direkte Produktübereinstimmung
        if (in_array($required_product_id, $product_ids_in_cart)) {
            $found_required_product = true;
            $found_products[] = $required_product_id;
            break;
        }

        // Direkte Variationsübereinstimmung
        if (in_array($required_product_id, $variation_ids_in_cart)) {
            $found_required_product = true;
            $found_products[] = $required_product_id . ' (Variation)';
            break;
        }

        // Überprüfe, ob eine der Variationen dem Elternprodukt entspricht
        foreach ($variation_parents as $variation_id => $parent_id) {
            if ($parent_id == $required_product_id && !in_array($variation_id, $bogof_excluded_variations)) {
                $found_required_product = true;
                $found_products[] = $required_product_id . ' (Elternprodukt der Variation ' . $variation_id . ')';
                break 2;
            }
        }
    }

    // Wenn kein erforderliches Produkt gefunden wurde, abbrechen
    if (!$found_required_product) {
        bogof_debug("Keine erforderlichen Produkte im Warenkorb gefunden oder alle sind ausgeschlossen");
        return;
    }
    bogof_debug("Erforderliche Produkte gefunden: " . implode(", ", $found_products));

    // Prüfe, ob das Gratisprodukt bereits im Warenkorb ist
    $free_product_in_cart = false;
    foreach ($cart_items as $cart_item_key => $cart_item) {
        if ($cart_item['product_id'] == $bogof_free_product_id && isset($cart_item['free_product'])) {
            $free_product_in_cart = true;
            bogof_debug("Gratisprodukt bereits im Warenkorb");
            break;
        }
    }

    // Wenn das Gratisprodukt noch nicht im Warenkorb ist, füge es hinzu
    if (!$free_product_in_cart) {
        bogof_debug("Versuche Gratisprodukt hinzuzufügen (ID: $bogof_free_product_id)");

        // Füge direkt Code ein, der das Produkt dem Warenkorb hinzufügt
        try {
            // Produkt direkt hinzufügen, ohne auf mögliche variable Produkte zu prüfen
            $cart_item_key = WC()->cart->add_to_cart($bogof_free_product_id, 1);

            if (!$cart_item_key) {
                bogof_debug("FEHLER: Produkt konnte nicht hinzugefügt werden. Versuche es als variables Produkt.");

                // Versuche es als variables Produkt falls vorhanden
                $product = wc_get_product($bogof_free_product_id);
                if ($product && $product->is_type('variable')) {
                    $available_variations = $product->get_available_variations();
                    if (!empty($available_variations)) {
                        $variation_id = $available_variations[0]['variation_id'];
                        $attributes = $available_variations[0]['attributes'];

                        $cart_item_key = WC()->cart->add_to_cart(
                            $bogof_free_product_id,
                            1,
                            $variation_id,
                            $attributes
                        );

                        bogof_debug("Variables Produkt: Variation $variation_id hinzugefügt");
                    }
                }
            }

            if ($cart_item_key) {
                bogof_debug("Produkt erfolgreich hinzugefügt mit key: $cart_item_key");

                // Markiere das Produkt als kostenlos und setze den Preis auf 0
                WC()->cart->cart_contents[$cart_item_key]['free_product'] = true;
                WC()->cart->cart_contents[$cart_item_key]['data']->set_price(0);

                // Aktualisiere den Warenkorb
                WC()->cart->set_session();

                // Zeige eine Nachricht an
                wc_add_notice(sprintf(
                    __('Ein kostenloses Produkt wurde zu deinem Warenkorb hinzugefügt!', 'woocommerce')
                ), 'success');
            } else {
                bogof_debug("KRITISCHER FEHLER: Produkt konnte nicht hinzugefügt werden");
            }
        } catch (Exception $e) {
            bogof_debug("Fehler beim Hinzufügen: " . $e->getMessage());
        }
    }
}
// Hooks für das Hinzufügen von Produkten
add_action('woocommerce_before_calculate_totals', 'add_free_product_with_coupon', 10);
add_action('woocommerce_applied_coupon', 'bogof_check_coupon');

/**
 * Wird aufgerufen, wenn ein Coupon angewendet wird
 */
function bogof_check_coupon($coupon_code)
{
    global $bogof_coupon_codes, $bogof_required_products, $bogof_excluded_variations;

    if (in_array(strtolower($coupon_code), array_map('strtolower', $bogof_coupon_codes))) {
        bogof_debug("Coupon $coupon_code wurde gerade angewendet");

        // Prüfe, ob die erforderlichen Produkte im Warenkorb sind
        $found_required_product = false;
        $cart_items = WC()->cart->get_cart();

        // Sammle alle Produkt-IDs und Variation-IDs im Warenkorb
        $product_ids_in_cart = [];
        $variation_ids_in_cart = [];
        $variation_parents = [];

        foreach ($cart_items as $cart_item) {
            $product_id = $cart_item['product_id'];
            $variation_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;

            // Wenn die Variation ausgeschlossen ist, überspringen
            if ($variation_id > 0 && !empty($bogof_excluded_variations) && in_array($variation_id, $bogof_excluded_variations)) {
                continue;
            }

            $product_ids_in_cart[] = $product_id;

            if ($variation_id > 0) {
                $variation_ids_in_cart[] = $variation_id;

                // Parent-ID für die Variation speichern
                $product = wc_get_product($variation_id);
                if ($product) {
                    $variation_parents[$variation_id] = $product->get_parent_id();
                }
            }
        }

        // Prüfe, ob eines der erforderlichen Produkte im Warenkorb ist
        foreach ($bogof_required_products as $required_product_id) {
            // Direkte Produktübereinstimmung
            if (in_array($required_product_id, $product_ids_in_cart)) {
                $found_required_product = true;
                break;
            }

            // Direkte Variationsübereinstimmung
            if (in_array($required_product_id, $variation_ids_in_cart)) {
                $found_required_product = true;
                break;
            }

            // Überprüfe, ob eine der Variationen dem Elternprodukt entspricht
            foreach ($variation_parents as $variation_id => $parent_id) {
                if ($parent_id == $required_product_id && (!empty($bogof_excluded_variations) && !in_array($variation_id, $bogof_excluded_variations))) {
                    $found_required_product = true;
                    break 2;
                }
            }
        }

        // Wenn kein erforderliches Produkt gefunden wurde, Fehler anzeigen und Gutschein entfernen
        if (!$found_required_product) {
            // Entferne den Gutschein
            WC()->cart->remove_coupon($coupon_code);

            // Fehlermeldung mit wc_add_notice ausgeben - ohne spezifische Produktnamen
            wc_add_notice(
                sprintf(
                    __('Der Gutscheincode "%s" kann mit den Produkten in Ihrem Warenkorb nicht verwendet werden.', 'woocommerce'),
                    $coupon_code
                ),
                'error'
            );

            return;
        }

        // Wenn ein gültiges Produkt gefunden wurde, führe die normale Aktion aus
        add_free_product_with_coupon();
    }
}

/**
 * Stellt sicher, dass das Gratisprodukt kostenlos bleibt
 */
function set_free_product_price($cart)
{
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    if (did_action('woocommerce_before_calculate_totals') >= 2) {
        return;
    }

    foreach ($cart->get_cart() as $cart_item) {
        if (isset($cart_item['free_product']) && $cart_item['free_product']) {
            $cart_item['data']->set_price(0);
            bogof_debug("Preis für Gratisprodukt auf 0 gesetzt");
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'set_free_product_price', 99);

/**
 * Begrenzt die Anzahl des Gratisprodukts auf maximal 1
 */
function limit_free_product_quantity($cart_contents)
{
    global $bogof_free_product_id, $bogof_max_quantity;

    foreach ($cart_contents as $cart_item_key => $cart_item) {
        // Prüfen, ob es sich um unser kostenloses Produkt handelt
        if (
            isset($cart_item['free_product']) && $cart_item['free_product'] &&
            $cart_item['product_id'] == $bogof_free_product_id
        ) {

            // Wenn die Menge größer als das Maximum ist, setze sie zurück
            if ($cart_item['quantity'] > $bogof_max_quantity) {
                $cart_contents[$cart_item_key]['quantity'] = $bogof_max_quantity;
                bogof_debug("Menge des Gratisprodukts auf $bogof_max_quantity begrenzt");
                wc_add_notice(
                    sprintf(__('Die Menge des kostenlosen Produkts wurde auf %d begrenzt, da es sich um ein Geschenk handelt.', 'woocommerce'), $bogof_max_quantity),
                    'notice'
                );
            }
        }
    }

    return $cart_contents;
}
add_filter('woocommerce_cart_contents_changed', 'limit_free_product_quantity');

/**
 * Verhindert, dass die Menge des Gratisprodukts beim Checkout erhöht werden kann
 */
function disable_free_product_quantity_changes()
{
    global $bogof_free_product_id;

    $cart_items = WC()->cart->get_cart();

    foreach ($cart_items as $cart_item_key => $cart_item) {
        if (
            isset($cart_item['free_product']) && $cart_item['free_product'] &&
            $cart_item['product_id'] == $bogof_free_product_id
        ) {

            // Füge JavaScript hinzu, um die Mengenänderung zu deaktivieren
?>
<script type="text/javascript">
jQuery(document).ready(function($) {
    // Deaktiviere die Mengenänderung für das Gratisprodukt
    $('form.woocommerce-cart-form').on('click',
        'input[type=number][name="cart[<?php echo $cart_item_key; ?>][qty]"]',
        function(e) {
            $(this).attr('readonly', true);
            $(this).attr('title',
                '<?php _e('Die Menge dieses kostenlosen Produkts kann nicht geändert werden', 'woocommerce'); ?>'
            );
        });

    // Setze die Werte auf 1 zurück, falls sie geändert wurden
    $('form.woocommerce-cart-form').on('change',
        'input[type=number][name="cart[<?php echo $cart_item_key; ?>][qty]"]',
        function(e) {
            $(this).val(1);
        });
});
</script>
<?php

            break;
        }
    }
}
add_action('woocommerce_after_cart_table', 'disable_free_product_quantity_changes');
add_action('woocommerce_after_checkout_form', 'disable_free_product_quantity_changes');

/**
 * Verhindert, dass die Menge des Gratisprodukts über die AJAX-Funktionen erhöht werden kann
 */
function validate_cart_item_quantity($passed, $cart_item_key, $values, $quantity)
{
    global $bogof_free_product_id, $bogof_max_quantity;

    // Wenn es sich um das Gratisprodukt handelt und jemand versucht, mehr als die erlaubte Menge hinzuzufügen
    if (
        isset($values['free_product']) && $values['free_product'] &&
        $values['product_id'] == $bogof_free_product_id && $quantity > $bogof_max_quantity
    ) {

        wc_add_notice(
            sprintf(__('Die maximale Menge für dieses kostenlose Geschenk ist %d.', 'woocommerce'), $bogof_max_quantity),
            'error'
        );

        return false;
    }

    return $passed;
}
add_filter('woocommerce_update_cart_validation', 'validate_cart_item_quantity', 10, 4);

/**
 * Entfernt das Gratisprodukt, wenn der Coupon entfernt wird oder die erforderlichen Produkte entfernt werden
 * oder wenn das aktuelle Datum außerhalb des gültigen Zeitraums liegt
 */
function remove_free_product_if_requirements_not_met()
{
    global $bogof_required_products, $bogof_free_product_id, $bogof_coupon_codes,
        $bogof_start_date, $bogof_end_date;

    // Wenn Warenkorb nicht verfügbar ist, abbrechen
    if (!function_exists('WC') || !isset(WC()->cart)) {
        return;
    }

    // Prüfe, ob das aktuelle Datum im gültigen Zeitraum liegt
    $current_date = date('Y-m-d');
    $date_valid = ($current_date >= $bogof_start_date && $current_date <= $bogof_end_date);

    // Wenn das Datum nicht gültig ist, Gratisprodukt entfernen
    if (!$date_valid) {
        bogof_debug("Datum nicht gültig: $current_date liegt außerhalb $bogof_start_date - $bogof_end_date");
        remove_bogof_product();
        return;
    }

    // Prüfe, ob der Coupon angewendet wurde
    $applied_coupons = WC()->cart->get_applied_coupons();
    $coupon_applied = !empty(array_intersect(array_map('strtolower', $bogof_coupon_codes), array_map('strtolower', $applied_coupons)));

    if (!$coupon_applied) {
        bogof_debug("Keiner der Coupons wurde angewendet, entferne Gratisprodukt");
        remove_bogof_product();
        return;
    }

    // Prüfe, ob mindestens eines der erforderlichen Produkte im Warenkorb ist
    $found_required_product = false;

    // Durchlaufe jedes erforderliche Produkt und prüfe ob es im Warenkorb ist
    foreach ($bogof_required_products as $required_product_id) {
        // Prüfe auf exakte Übereinstimmung oder ob es die Eltern-ID einer Variation ist
        if (bogof_is_product_in_cart($required_product_id)) {
            $found_required_product = true;
            break;
        }
    }

    if (!$found_required_product) {
        bogof_debug("Keine erforderlichen Produkte im Warenkorb, entferne Gratisprodukt");
        remove_bogof_product();
        return;
    }
}
add_action('woocommerce_before_calculate_totals', 'remove_free_product_if_requirements_not_met', 5);

/**
 * Hilfsfunktion zum Entfernen des Gratisprodukts
 */
function remove_bogof_product()
{
    global $bogof_free_product_id;

    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        if (
            $cart_item['product_id'] == $bogof_free_product_id &&
            isset($cart_item['free_product']) && $cart_item['free_product']
        ) {
            WC()->cart->remove_cart_item($cart_item_key);
            bogof_debug("Gratisprodukt entfernt");
            break;
        }
    }
}
?>