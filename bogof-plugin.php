<?php
/*
Plugin Name: BOGOF - Buy One Get One Free
Description: Fügt automatisch ein Gratisprodukt zum Warenkorb hinzu, wenn bestimmte Bedingungen erfüllt sind
Version: 1.0
Author: Daniel Hilmer
*/

// Sicherheitscheck
if (!defined("ABSPATH")) {
    exit();
}

// ===== KONFIGURATION =====

// Debug-Modus (true/false)
$bogof_debug = false; // Auf false setzen für Produktivumgebung

// ===== KAMPAGNEN-KLASSEN =====

/**
 * Klasse für eine einzelne BOGOF-Kampagne
 */
class BOGOF_Campaign
{
    public $name;
    public $coupon_codes;
    public $required_products;
    public $excluded_variations;
    public $free_product_id;
    public $free_variation_id;
    public $start_date;
    public $end_date;
    public $max_quantity;
    public $active;

    public function __construct($config)
    {
        $this->name = $config["name"] ?? "Unbenannte Kampagne";
        $this->coupon_codes = array_map(
            "strtolower",
            $config["coupon_codes"] ?? [],
        );
        $this->required_products = $config["required_products"] ?? [];
        $this->excluded_variations = $config["excluded_variations"] ?? [];
        $this->free_product_id = $config["free_product_id"] ?? 0;
        $this->free_variation_id = $config["free_variation_id"] ?? 0;
        $this->start_date = $config["start_date"] ?? null;
        $this->end_date = $config["end_date"] ?? null;
        $this->max_quantity = $config["max_quantity"] ?? 1;
        $this->active = $config["active"] ?? true;
    }

    /**
     * Prüft, ob die Kampagne aktuell gültig ist (Datum und Aktivierung)
     */
    public function is_valid()
    {
        if (!$this->active) {
            return false;
        }

        $current_date = date("Y-m-d");

        // Prüfe Startdatum
        if ($this->start_date && $current_date < $this->start_date) {
            return false;
        }

        // Prüfe Enddatum (null bedeutet unbegrenzt)
        if ($this->end_date && $current_date > $this->end_date) {
            return false;
        }

        return true;
    }

    /**
     * Prüft, ob einer der Gutscheincodes angewendet wurde
     */
    public function has_valid_coupon()
    {
        $applied_coupons = array_map(
            "strtolower",
            WC()->cart->get_applied_coupons(),
        );
        return !empty(array_intersect($this->coupon_codes, $applied_coupons));
    }
}

/**
 * Manager-Klasse für alle BOGOF-Kampagnen
 */
class BOGOF_Campaign_Manager
{
    private static $instance = null;
    private $campaigns = [];

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->load_campaigns();
    }

    /**
     * Lädt alle Kampagnen aus der Konfigurationsdatei
     */
    private function load_campaigns()
    {
        $config_file = plugin_dir_path(__FILE__) . "bogof-campaigns.php";

        if (!file_exists($config_file)) {
            bogof_debug("Konfigurationsdatei nicht gefunden: $config_file");
            return;
        }

        $campaigns_config = include $config_file;

        if (!is_array($campaigns_config)) {
            bogof_debug("Ungültige Konfigurationsdatei");
            return;
        }

        foreach ($campaigns_config as $config) {
            $this->campaigns[] = new BOGOF_Campaign($config);
        }

        bogof_debug("Geladene Kampagnen: " . count($this->campaigns));
    }

    /**
     * Findet die erste passende aktive Kampagne
     */
    public function find_matching_campaign()
    {
        foreach ($this->campaigns as $campaign) {
            if (!$campaign->is_valid()) {
                bogof_debug(
                    "Kampagne '{$campaign->name}' ist nicht gültig (inaktiv oder außerhalb des Zeitraums)",
                );
                continue;
            }

            if (!$campaign->has_valid_coupon()) {
                bogof_debug(
                    "Kampagne '{$campaign->name}' hat keinen gültigen Gutschein",
                );
                continue;
            }

            // Prüfe, ob erforderliche Produkte im Warenkorb sind
            if ($this->has_required_products($campaign)) {
                bogof_debug("Passende Kampagne gefunden: '{$campaign->name}'");
                return $campaign;
            }

            bogof_debug(
                "Kampagne '{$campaign->name}' hat keine erforderlichen Produkte im Warenkorb",
            );
        }

        return null;
    }

    /**
     * Prüft, ob die erforderlichen Produkte einer Kampagne im Warenkorb sind
     */
    public function has_required_products($campaign)
    {
        $cart_items = WC()->cart->get_cart();

        // Sammle alle Produkt-IDs und Variation-IDs im Warenkorb
        $product_ids_in_cart = [];
        $variation_ids_in_cart = [];
        $variation_parents = [];

        foreach ($cart_items as $cart_item) {
            $product_id = $cart_item["product_id"];
            $variation_id = !empty($cart_item["variation_id"])
                ? $cart_item["variation_id"]
                : 0;

            // Wenn die Variation ausgeschlossen ist, überspringen
            if (
                $variation_id > 0 &&
                in_array($variation_id, $campaign->excluded_variations)
            ) {
                continue;
            }

            $product_ids_in_cart[] = $product_id;

            if ($variation_id > 0) {
                $variation_ids_in_cart[] = $variation_id;

                // Parent-ID für die Variation speichern
                $product = wc_get_product($variation_id);
                if ($product) {
                    $variation_parents[
                        $variation_id
                    ] = $product->get_parent_id();
                }
            }
        }

        // Prüfe, ob eines der erforderlichen Produkte im Warenkorb ist
        foreach ($campaign->required_products as $required_product_id) {
            // Direkte Produktübereinstimmung
            if (in_array($required_product_id, $product_ids_in_cart)) {
                return true;
            }

            // Direkte Variationsübereinstimmung
            if (in_array($required_product_id, $variation_ids_in_cart)) {
                return true;
            }

            // Überprüfe, ob eine der Variationen dem Elternprodukt entspricht
            foreach ($variation_parents as $variation_id => $parent_id) {
                if (
                    $parent_id == $required_product_id &&
                    !in_array($variation_id, $campaign->excluded_variations)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Gibt alle aktiven Kampagnen zurück
     */
    public function get_active_campaigns()
    {
        return array_filter($this->campaigns, function ($campaign) {
            return $campaign->is_valid();
        });
    }
}

// ===== FUNKTIONEN =====

/**
 * Debug-Funktion für BOGOF
 */
function bogof_debug($message)
{
    global $bogof_debug;
    if ($bogof_debug && current_user_can("manage_options")) {
        wc_add_notice("[DEBUG] " . $message, "notice");
    }
}

/**
 * Prüft, ob ein Produkt eine der ausgeschlossenen Variations-IDs hat (für eine bestimmte Kampagne)
 */
function bogof_is_excluded_variation($campaign, $product_id, $variation_id = 0)
{
    // Wenn keine Variations-IDs zum Ausschließen definiert sind, überspringen
    if (empty($campaign->excluded_variations)) {
        return false;
    }

    // Wenn es eine Variation ist, direkt prüfen
    if (
        $variation_id > 0 &&
        in_array($variation_id, $campaign->excluded_variations)
    ) {
        bogof_debug(
            "Variation mit ID $variation_id ist in Kampagne '{$campaign->name}' ausgeschlossen",
        );
        return true;
    }

    return false;
}

/**
 * Fügt automatisch ein Gratisprodukt zum Warenkorb hinzu basierend auf passenden Kampagnen
 */
function add_free_product_with_coupon()
{
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

    // Hole den Kampagnen-Manager
    $campaign_manager = BOGOF_Campaign_Manager::get_instance();

    // Finde die erste passende Kampagne
    $active_campaign = $campaign_manager->find_matching_campaign();

    if (!$active_campaign) {
        bogof_debug("Keine passende Kampagne gefunden");
        return;
    }

    bogof_debug("Aktive Kampagne: '{$active_campaign->name}'");

    // Prüfe, ob das Gratisprodukt bereits im Warenkorb ist
    $cart_items = WC()->cart->get_cart();
    $free_product_in_cart = false;

    foreach ($cart_items as $cart_item_key => $cart_item) {
        if (
            $cart_item["product_id"] == $active_campaign->free_product_id &&
            isset($cart_item["free_product"])
        ) {
            $free_product_in_cart = true;
            bogof_debug("Gratisprodukt bereits im Warenkorb");
            break;
        }
    }

    // Wenn das Gratisprodukt noch nicht im Warenkorb ist, füge es hinzu
    if (!$free_product_in_cart) {
        bogof_debug(
            "Versuche Gratisprodukt hinzuzufügen (ID: {$active_campaign->free_product_id})",
        );

        try {
            $cart_item_key = null;

            // Prüfe, ob eine spezifische Variation gewünscht ist
            if ($active_campaign->free_variation_id > 0) {
                $product = wc_get_product($active_campaign->free_product_id);
                if ($product && $product->is_type("variable")) {
                    $variation_product = wc_get_product(
                        $active_campaign->free_variation_id,
                    );
                    if ($variation_product) {
                        $attributes = $variation_product->get_variation_attributes();
                        $cart_item_key = WC()->cart->add_to_cart(
                            $active_campaign->free_product_id,
                            1,
                            $active_campaign->free_variation_id,
                            $attributes,
                        );
                        bogof_debug(
                            "Spezifische Variation {$active_campaign->free_variation_id} hinzugefügt",
                        );
                    }
                }
            } else {
                // Versuche einfaches Produkt hinzuzufügen
                $cart_item_key = WC()->cart->add_to_cart(
                    $active_campaign->free_product_id,
                    1,
                );

                if (!$cart_item_key) {
                    // Versuche es als variables Produkt mit erster verfügbarer Variation
                    $product = wc_get_product(
                        $active_campaign->free_product_id,
                    );
                    if ($product && $product->is_type("variable")) {
                        $available_variations = $product->get_available_variations();
                        if (!empty($available_variations)) {
                            $variation_id =
                                $available_variations[0]["variation_id"];
                            $attributes =
                                $available_variations[0]["attributes"];

                            $cart_item_key = WC()->cart->add_to_cart(
                                $active_campaign->free_product_id,
                                1,
                                $variation_id,
                                $attributes,
                            );
                            bogof_debug(
                                "Variables Produkt: Erste verfügbare Variation $variation_id hinzugefügt",
                            );
                        }
                    }
                }
            }

            if ($cart_item_key) {
                bogof_debug(
                    "Produkt erfolgreich hinzugefügt mit key: $cart_item_key",
                );

                // Markiere das Produkt als kostenlos und setze den Preis auf 0
                WC()->cart->cart_contents[$cart_item_key][
                    "free_product"
                ] = true;
                WC()->cart->cart_contents[$cart_item_key]["campaign_name"] =
                    $active_campaign->name;
                WC()->cart->cart_contents[$cart_item_key]["data"]->set_price(0);

                // Aktualisiere den Warenkorb
                WC()->cart->set_session();

                // Zeige eine Nachricht an
                wc_add_notice(
                    sprintf(
                        __(
                            'Ein kostenloses Produkt wurde aus der "%s" zu deinem Warenkorb hinzugefügt!',
                            "woocommerce",
                        ),
                        $active_campaign->name,
                    ),
                    "success",
                );
            } else {
                bogof_debug(
                    "KRITISCHER FEHLER: Produkt konnte nicht hinzugefügt werden",
                );
            }
        } catch (Exception $e) {
            bogof_debug("Fehler beim Hinzufügen: " . $e->getMessage());
        }
    }
}
// Hooks für das Hinzufügen von Produkten
add_action(
    "woocommerce_before_calculate_totals",
    "add_free_product_with_coupon",
    10,
);
add_action("woocommerce_applied_coupon", "bogof_check_coupon");

/**
 * Wird aufgerufen, wenn ein Coupon angewendet wird
 */
function bogof_check_coupon($coupon_code)
{
    bogof_debug("Coupon $coupon_code wurde angewendet, prüfe Kampagnen");

    // Hole den Kampagnen-Manager
    $campaign_manager = BOGOF_Campaign_Manager::get_instance();

    // Prüfe, ob der Coupon zu einer aktiven Kampagne gehört
    $matching_campaign = null;
    foreach ($campaign_manager->get_active_campaigns() as $campaign) {
        if (in_array(strtolower($coupon_code), $campaign->coupon_codes)) {
            $matching_campaign = $campaign;
            break;
        }
    }

    if (!$matching_campaign) {
        bogof_debug(
            "Coupon $coupon_code gehört zu keiner aktiven BOGOF-Kampagne",
        );
        return;
    }

    bogof_debug(
        "Coupon $coupon_code gehört zur Kampagne '{$matching_campaign->name}'",
    );

    // Prüfe, ob bereits ein anderer BOGOF-Gutschein aktiv ist
    $applied_coupons = WC()->cart->get_applied_coupons();
    $bogof_coupons_in_cart = [];

    foreach ($applied_coupons as $applied_coupon) {
        foreach ($campaign_manager->get_active_campaigns() as $campaign) {
            if (
                in_array(strtolower($applied_coupon), $campaign->coupon_codes)
            ) {
                $bogof_coupons_in_cart[] = $applied_coupon;
            }
        }
    }

    // Wenn bereits mehr als ein BOGOF-Gutschein aktiv ist (inklusive dem gerade hinzugefügten)
    if (count($bogof_coupons_in_cart) > 1) {
        // Entferne den gerade hinzugefügten Gutschein
        WC()->cart->remove_coupon($coupon_code);

        // Fehlermeldung ausgeben
        wc_add_notice(
            __(
                "Du kannst nur einen Gutschein pro Bestellung aktivieren.",
                "woocommerce",
            ),
            "error",
        );

        bogof_debug(
            "Mehrere BOGOF-Gutscheine erkannt, $coupon_code wurde entfernt",
        );
        return;
    }

    // Prüfe, ob die erforderlichen Produkte im Warenkorb sind
    if (!$campaign_manager->find_matching_campaign()) {
        // Entferne den Gutschein, da die Bedingungen nicht erfüllt sind
        WC()->cart->remove_coupon($coupon_code);

        // Fehlermeldung mit wc_add_notice ausgeben
        wc_add_notice(
            sprintf(
                __(
                    'Der Gutscheincode "%s" kann mit den Produkten in Ihrem Warenkorb nicht verwendet werden.',
                    "woocommerce",
                ),
                $coupon_code,
            ),
            "error",
        );

        return;
    }

    // Wenn alle Bedingungen erfüllt sind, führe die normale Aktion aus
    add_free_product_with_coupon();
}

/**
 * Stellt sicher, dass das Gratisprodukt kostenlos bleibt
 */
function set_free_product_price($cart)
{
    if (is_admin() && !defined("DOING_AJAX")) {
        return;
    }

    if (did_action("woocommerce_before_calculate_totals") >= 2) {
        return;
    }

    foreach ($cart->get_cart() as $cart_item) {
        if (isset($cart_item["free_product"]) && $cart_item["free_product"]) {
            $cart_item["data"]->set_price(0);
            bogof_debug("Preis für Gratisprodukt auf 0 gesetzt");
        }
    }
}
add_action("woocommerce_before_calculate_totals", "set_free_product_price", 99);

/**
 * Begrenzt die Anzahl des Gratisprodukts basierend auf der jeweiligen Kampagne
 */
function limit_free_product_quantity($cart_contents)
{
    $campaign_manager = BOGOF_Campaign_Manager::get_instance();

    foreach ($cart_contents as $cart_item_key => $cart_item) {
        // Prüfen, ob es sich um ein kostenloses Produkt handelt
        if (isset($cart_item["free_product"]) && $cart_item["free_product"]) {
            // Finde die passende Kampagne für dieses Produkt
            $matching_campaign = null;
            foreach ($campaign_manager->get_active_campaigns() as $campaign) {
                if ($campaign->free_product_id == $cart_item["product_id"]) {
                    $matching_campaign = $campaign;
                    break;
                }
            }

            if ($matching_campaign) {
                // Wenn die Menge größer als das Maximum ist, setze sie zurück
                if ($cart_item["quantity"] > $matching_campaign->max_quantity) {
                    $cart_contents[$cart_item_key]["quantity"] =
                        $matching_campaign->max_quantity;
                    bogof_debug(
                        "Menge des Gratisprodukts in Kampagne '{$matching_campaign->name}' auf {$matching_campaign->max_quantity} begrenzt",
                    );
                    wc_add_notice(
                        sprintf(
                            __(
                                'Die Menge des kostenlosen Produkts aus "%s" wurde auf %d begrenzt.',
                                "woocommerce",
                            ),
                            $matching_campaign->name,
                            $matching_campaign->max_quantity,
                        ),
                        "notice",
                    );
                }
            }
        }
    }

    return $cart_contents;
}
add_filter("woocommerce_cart_contents_changed", "limit_free_product_quantity");

/**
 * Verhindert, dass die Menge des Gratisprodukts beim Checkout erhöht werden kann
 */
function disable_free_product_quantity_changes()
{
    $cart_items = WC()->cart->get_cart();

    foreach ($cart_items as $cart_item_key => $cart_item) {
        if (isset($cart_item["free_product"]) && $cart_item["free_product"]) {

            // Hole die maximale Anzahl aus der Kampagne
            $max_quantity = isset($cart_item["campaign_name"]) ? 1 : 1; // Fallback auf 1

            $campaign_manager = BOGOF_Campaign_Manager::get_instance();
            foreach ($campaign_manager->get_active_campaigns() as $campaign) {
                if ($campaign->free_product_id == $cart_item["product_id"]) {
                    $max_quantity = $campaign->max_quantity;
                    break;
                }
            }

            // Füge JavaScript hinzu, um die Mengenänderung zu begrenzen
            ?>
<script type="text/javascript">
jQuery(document).ready(function($) {
    // Deaktiviere die Mengenänderung für das Gratisprodukt
    $('form.woocommerce-cart-form').on('click',
        'input[type=number][name="cart[<?php echo $cart_item_key; ?>][qty]"]',
        function(e) {
            $(this).attr('readonly', true);
            $(this).attr('title',
                '<?php _e(
                    "Die Menge dieses kostenlosen Produkts kann nicht geändert werden",
                    "woocommerce",
                ); ?>'
            );
        });

    // Setze die Werte auf das Maximum zurück, falls sie geändert wurden
    $('form.woocommerce-cart-form').on('change',
        'input[type=number][name="cart[<?php echo $cart_item_key; ?>][qty]"]',
        function(e) {
            $(this).val(<?php echo $max_quantity; ?>);
        });
});
</script>
<?php break;
        }
    }
}
add_action(
    "woocommerce_after_cart_table",
    "disable_free_product_quantity_changes",
);
add_action(
    "woocommerce_after_checkout_form",
    "disable_free_product_quantity_changes",
);

/**
 * Verhindert, dass die Menge des Gratisprodukts über die AJAX-Funktionen erhöht werden kann
 */
function validate_cart_item_quantity(
    $passed,
    $cart_item_key,
    $values,
    $quantity,
) {
    // Wenn es sich um ein kostenloses Produkt handelt
    if (isset($values["free_product"]) && $values["free_product"]) {
        // Finde die passende Kampagne
        $campaign_manager = BOGOF_Campaign_Manager::get_instance();
        $max_quantity = 1; // Fallback

        foreach ($campaign_manager->get_active_campaigns() as $campaign) {
            if ($campaign->free_product_id == $values["product_id"]) {
                $max_quantity = $campaign->max_quantity;
                break;
            }
        }

        if ($quantity > $max_quantity) {
            wc_add_notice(
                sprintf(
                    __(
                        "Die maximale Menge für dieses kostenlose Geschenk ist %d.",
                        "woocommerce",
                    ),
                    $max_quantity,
                ),
                "error",
            );
            return false;
        }
    }

    return $passed;
}
add_filter(
    "woocommerce_update_cart_validation",
    "validate_cart_item_quantity",
    10,
    4,
);

/**
 * Entfernt Gratisprodukte, wenn die Bedingungen der Kampagnen nicht mehr erfüllt sind
 */
function remove_free_product_if_requirements_not_met()
{
    // Wenn Warenkorb nicht verfügbar ist, abbrechen
    if (!function_exists("WC") || !isset(WC()->cart)) {
        return;
    }

    $campaign_manager = BOGOF_Campaign_Manager::get_instance();
    $cart_items = WC()->cart->get_cart();

    // Durchlaufe alle Warenkorb-Items und prüfe kostenlose Produkte
    foreach ($cart_items as $cart_item_key => $cart_item) {
        if (isset($cart_item["free_product"]) && $cart_item["free_product"]) {
            // Finde die passende Kampagne für dieses Gratisprodukt
            $matching_campaign = null;
            foreach ($campaign_manager->get_active_campaigns() as $campaign) {
                if ($campaign->free_product_id == $cart_item["product_id"]) {
                    // Prüfe alle Bedingungen der Kampagne
                    if (
                        $campaign->is_valid() &&
                        $campaign->has_valid_coupon() &&
                        $campaign_manager->has_required_products($campaign)
                    ) {
                        $matching_campaign = $campaign;
                        break;
                    }
                }
            }

            // Wenn keine passende Kampagne gefunden wurde, entferne das Produkt
            if (!$matching_campaign) {
                WC()->cart->remove_cart_item($cart_item_key);
                bogof_debug(
                    "Gratisprodukt entfernt (ID: {$cart_item["product_id"]}) - Bedingungen nicht mehr erfüllt",
                );
            }
        }
    }
}
add_action(
    "woocommerce_before_calculate_totals",
    "remove_free_product_if_requirements_not_met",
    5,
);

/**
 * Hilfsfunktion zum Entfernen aller kostenlosen BOGOF-Produkte
 */
function remove_bogof_product()
{
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        if (isset($cart_item["free_product"]) && $cart_item["free_product"]) {
            WC()->cart->remove_cart_item($cart_item_key);
            bogof_debug(
                "Gratisprodukt entfernt (ID: {$cart_item["product_id"]})",
            );
        }
    }
}
?>
