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
if (!defined('ABSPATH')) {
    exit;
}

return [
    // Jubiläums-Kampagne (bestehende Konfiguration)
    [
        'name' => 'Hagebutte',
        'coupon_codes' => ['hagebutte'],
        'required_products' => [698, 4239],
        'excluded_variations' => [7485, 7484],
        'free_product_id' => 9624,
        'free_variation_id' => 0,
        'start_date' => '2025-05-11',
        'end_date' => null,
        'max_quantity' => 1,
        'active' => true
    ],
    [
        'name' => 'Leinöl',
        'coupon_codes' => ['leinöl'],
        'required_products' => [698, 4239],
        'excluded_variations' => [7485, 7484],
        'free_product_id' => 9136,
        'free_variation_id' => 9929,
        'start_date' => '2025-05-11',
        'end_date' => null,
        'max_quantity' => 1,
        'active' => true
    ],
];