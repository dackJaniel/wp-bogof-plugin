<?php
/**
 * BOGOF Kampagnen-Konfiguration
 *
 * Jede Kampagne kann individuelle Einstellungen haben:
 * - name: Name der Kampagne (für Debug/Logs)
 * - coupon_codes: Array der gültigen Gutscheincodes
 * - required_products: Array der Produkt-IDs, die im Warenkorb sein müssen
 * - excluded_variations: Array der Variations-IDs, die ausgeschlossen sind
 * - free_product_id: ID des Gratisprodukts
 * - free_variation_id: ID der spezifischen Variation (0 = erste verfügbare)
 * - start_date: Startdatum (YYYY-MM-DD) oder null für sofortigen Start
 * - end_date: Enddatum (YYYY-MM-DD) oder null für unbegrenztes Ende
 * - max_quantity: Maximale Anzahl des Gratisprodukts
 * - active: true/false - ob die Kampagne aktiv ist
 */

// Sicherheitscheck
if (!defined("ABSPATH")) {
    exit();
}

return [
    [
        "name" => "NAME",
        "coupon_codes" => ["CODE1", "CODE2"],
        "required_products" => [1, 2, 3],
        "excluded_variations" => [4, 5],
        "free_product_id" => 6,
        "free_variation_id" => 0,
        "start_date" => "2025-05-11",
        "end_date" => null,
        "max_quantity" => 1,
        "active" => true,
    ],
    [
        "name" => "NAME",
        "coupon_codes" => ["CODE3", "CODE4"],
        "required_products" => [7, 8, 9],
        "excluded_variations" => [10],
        "free_product_id" => 11,
        "free_variation_id" => 12,
        "start_date" => "2025-05-11",
        "end_date" => null,
        "max_quantity" => 1,
        "active" => true,
    ],
];
