<?php

class Montapacking
{
    private static $WooCommerceShippingMethod = null;
    public static function shipping_package($packages)
    {
        self::$WooCommerceShippingMethod = self::getWooCommerceShippingMethod($packages[0]);
        return [];
    }

    public static function checkout_validate($data, $errors)
    {
        if (empty($_POST['montapacking']) || !is_array($_POST['montapacking'])) {
            return;
        }

        $type = sanitize_post($_POST['montapacking']);
        $pickup = $type['pickup'];
        $shipment = $type['shipment'];
        $time = $shipment['time'];

        $shipper = "";
        if (isset($shipment['shipper'])) {
            $shipper = $shipment['shipper'];
        }

        $items = null;

        if (!isset($shipment['type']) || $shipment['type'] == '') {
            $errors->add('shipment', __('Select a shipping method.', 'montapacking-checkout'));
        }

        switch ($shipment['type']) {
            case 'delivery':

                $frames = self::get_frames('delivery');
                if ($frames !== null) {

                    ## Frames naar handige array zetten
                    $items = self::format_frames($frames);
                }

                break;
            case 'pickup':

                if (!isset($pickup) || !isset($pickup['code']) || $pickup['code'] == '') {
                    $errors->add('shipment', __('Select a pickup location.', 'montapacking-checkout'));
                }


                if (isset($pickup) &&  isset($pickup['postnumber']) && trim($pickup['postnumber']) == '') {
                    $errors->add('shipment', __('Please enter a postal number, this is mandatory for this pick-up option', 'montapacking-checkout'));
                }

                break;
        }

        ## Check of timeframes bekend zijn en niet van een te oude sessie
        if ($items !== null) {

            $error = false;
            if (isset($items[$time])) {

                ## Check of timeframe opties heeft
                $frame = $items[$time];
                if (isset($frame->options)) {

                    ## Gekozen shipper ophalen
                    $found = false;
                    foreach ($frame->options as $option) {

                        if ($option->code == $shipper) {

                            $found = true;
                            break;
                        }
                    }

                    ## Check of optie is gevonden
                    if (!$found) {
                        $error = true;
                    }

                } else {

                    $error = true;
                }

            } else {

                $error = true;
            }

            if ($error) {
                $errors->add('shipment', __('The shipment option(s) you choose are not available at this time, please select an other option.', 'montapacking-checkout'));
            }
        }
    }

    public static function checkout_store(WC_Abstract_Order $order)
    {
        $hasDigitalProducts = false;
        $hasPhysicalProducts = false;

        foreach ($order->get_items() as $cart_item) {
            if ($cart_item['quantity']) {

                if ($cart_item['variation_id']) {
                    $product = wc_get_product($cart_item['variation_id']);
                } else {
                    $product = wc_get_product($cart_item['product_id']);
                }

                $virtual = $product->get_virtual();

                if ($virtual) {
                    ;
                    $hasDigitalProducts = true;
                } else {
                    $hasPhysicalProducts = true;
                }
            }
        }
        if ($hasPhysicalProducts == false && $hasDigitalProducts == true) {
            return;
        }

        $bMontapackingAdd = false;

        ## Shipping regel aanmaken bij order
        $item = new WC_Order_Item_Shipping();

        ## Ingevulde meta data opslaan
        $type = sanitize_post($_POST['montapacking']);

        $shipment = $type['shipment'];
        $time = $shipment['time'];
        $shipper = "";
        if (isset($shipment['shipper'])) {
            $shipper = $shipment['shipper'];
        }

        $extras = "";
        if (isset($shipment['extras'])) {
            $extras = $shipment['extras'];
        }

        $pickup = $type['pickup'];

        $items = null;
        switch ($shipment['type']) {
            case 'delivery':
                $frames = self::get_frames('delivery');
                if ($frames !== null) {

                    ## Frames naar handige array zetten
                    $items = self::format_frames($frames, $time);
                }

                break;
            case 'pickup':
            case 'collect':
                $name = $order->get_billing_first_name()." ".$order->get_billing_last_name();
                $pickup['name'] = $name;

                $order->set_shipping_first_name($order->get_billing_first_name());
                $order->set_shipping_last_name($order->get_billing_last_name());

                $order->set_shipping_company($pickup['company']);
                $order->set_shipping_address_1($pickup['street'] . " " . $pickup['houseNumber']);
                $order->set_shipping_postcode($pickup['postal']);
                $order->set_shipping_city($pickup['city']);
                $order->set_shipping_country($pickup['country']);

                // post nummer voor duitsland
                if ($pickup['postnumber'] && trim($pickup['postnumber']))
                {
                    if (isset($pickup['shippingOptions'])) {
                       $pickup['shippingOptions'] = $pickup['shippingOptions'].",DHLPCPostNummer_".$pickup['postnumber'] ;
                    } else {
                        $pickup['shippingOptions'] = "DHLPCPostNummer_".$pickup['postnumber'];
                    }
                    unset($pickup['postnumber']);
                }


                $item->add_meta_data('Pickup Data', $pickup, true);

                // setting up address in a nice array
                $arr = array();
                //$arr[] = $pickup['shipper'];
                //$arr[] = $pickup['code'];
                $arr[] = "<b>Ophaalpunt</b>";
                $arr[] = $pickup['description'];
                $arr[] = $pickup['company'];
                $arr[] = $pickup['name'];
                $arr[] = $pickup['street'] . " " . $pickup['houseNumber'];
                $arr[] = $pickup['postal'] . " " . $pickup['city'] . " (" . $pickup['country'] . ")";

                $arr = implode("\n\r", $arr);

                $item->add_meta_data('Leveringsopties', $arr, true);

                $bMontapackingAdd = true;

                break;
        }

        ## Check of gekozen timeframe bestaat
        if (isset($items[$time])) {
            $method = null;
            $shipperCode = null;

            ## Check of timeframe opties heeft
            $frame = $items[$time];
            if (isset($frame->options)) {

                ## Gekozen shipper ophalen
                foreach ($frame->options as $option) {

                    if ($option->code == $shipper) {

                        $shipperCode = implode(',', $option->codes);
                        $method = $option;
                        break;

                    }
                }
            }

            ## Check of verzendmethode is gevonden
            if ($method !== null) {

                ## Gekozen optie met datum en tijd toevoegen
                $item->add_meta_data('Shipmentmethod', $shipperCode, true);
                $item->add_meta_data('Delivery date', $frame->date, true);


                $time_check = $method->from . ' ' . $method->to;
                if ($time_check != '01:00 01:00' && trim($time_check) && $method->from != $method->to) {
                    $item->add_meta_data('Delivery timeframe', $method->from . ' ' . $method->to, true);
                }

                if ($method->type == 'shippingdate') {
                    $item->add_meta_data('Delivery type', "Date mentioned above is an send date", true);
                }

                if (is_array($extras)) {

                    if (!empty($method->optionCodes)) {

                        foreach ($method->optionCodes as $optionCode) {

                            array_push($extras, $optionCode);

                        }

                    }

                    $item->add_meta_data('Extras', implode(", ", $extras), true);

                } else if (!empty($method->optionCodes)) {

                    $extras = array();

                    foreach ($method->optionCodes as $optionCode) {

                        array_push($extras, $optionCode);
                    }

                    $item->add_meta_data('Extras', implode(", ", $extras), true);

                }

                $bMontapackingAdd = true;
            }

        }


        $api = new MontapackingShipping(esc_attr(get_option('monta_shop')), esc_attr(get_option('monta_username')), esc_attr(get_option('monta_password')), false);

        if (true !== $api->checkConnection()) {
            $arr = array();
            $arr[] = "Webshop was unable to connect to Montapacking REST api. Please contact Montapacking";
            $arr = implode("\n\r", $arr);

            $item->add_meta_data('No Connection', $arr, true);
        } else {
            if (false === $bMontapackingAdd) {

                $arr = array();

                switch ($shipment['type']) {
                    case 'delivery':
                        $arr[] = "1 - No shippers available for the chosen delivery address";
                        $arr = implode("\n\r", $arr);
                        $item->add_meta_data('1 - No shippers available for the chosen delivery address', $arr, true);
                        break;
                    case 'pickup':
                        $arr[] = "2 - No pickups available for the chosen delivery address";
                        $arr = implode("\n\r", $arr);
                        $item->add_meta_data('2 - No pickup address chosen ', $arr, true);
                        break;
                }
            }
        }

        $price = wc_format_decimal(self::get_shipping_total(sanitize_post($_POST)));

        if (wc_tax_enabled() && WC()->cart->display_prices_including_tax()) {

            $mixed = WC_Tax::get_shipping_tax_rates(null, null);

            $found = false;
            $vat_percent = 0;
            $id = "";
            foreach ($mixed as $key => $obj) {
                $vat_percent = $obj['rate'];
                $found = true;
                $id = ":" . $key;
                break;
            }

            $vat_calculate = 100 + $vat_percent;


            $tax = (self::get_shipping_total(sanitize_post($_POST)) / $vat_calculate) * $vat_percent;

            $rate = new WC_Shipping_Rate('flat_rate_shipping' . $id, 'Webshop verzendmethode', (double)$price - $tax, $tax, 'flat_rate');

            $item->set_props(array(
                'method_title' => $rate->label,
                'method_id' => $rate->id,
                'total' => wc_format_decimal($rate->cost),
                'taxes' => $rate->taxes,
                'meta_data' => $rate->get_meta_data()
                ));

            $order->add_item($item);
            $order->save();

            WC()->session->set('chosen_shipping_methods', ['flat_rate_shipping' . $id] );
            $order->calculate_totals(true);
        } else {

            $item->set_props(array(
                'method_title' => 'Webshop verzendmethode',
                'method_id' => 0,
                'total' => $price,
            ));

            WC()->session->set('chosen_shipping_methods', [0] );
            $order->add_item($item);
        }
        $order->save();
    }

    public static function shipping_total($wc_price = 0)
    {        
        if (did_filter('woocommerce_cart_get_total') >= 4) {
            return $wc_price;
        }
        
        $data = null;
        if (isset($_POST['montapacking'])) {
            $data = sanitize_post($_POST);
        }

        $price = (float)self::get_shipping_total($data);

        return $wc_price + $price;
    }

    public static function shipping_calculate()
    {
        $data = null;
        if (isset($_POST['montapacking'])) {
            $data = sanitize_post($_POST);
        }

        $price = self::get_shipping_total($data);
        $datapost = null;
        if (isset($_POST['post_data'])) {
            parse_str(sanitize_post($_POST['post_data']), $datapost);
        }

        $selectedoption = false;


        if (isset($datapost['montapacking']['shipment']['type']) && $datapost['montapacking']['shipment']['type'] == 'delivery') {

            if (isset($datapost['montapacking']['shipment']['shipper'])) {
                $selectedoption = true;
            }

        }

        if (isset($datapost['montapacking']['shipment']['type']) && $datapost['montapacking']['shipment']['type'] == 'pickup') {
            if (isset($datapost['montapacking']['pickup']['code']) && trim($datapost['montapacking']['pickup']['code'])) {
                $selectedoption = true;
            }
        }


        ?>
        <tr>
            <th><?php _e('Shipping', 'woocommerce'); ?> </th>
            <?php
            if ($price == 0 && false == $selectedoption) {
                ?>
                <td><?php _e('Choose a shipping method', 'montapacking-checkout'); ?></td>
                <?php
            } else if ($price > 0) {
                ?>
                <td>&euro; <?php echo number_format($price, 2, ',', ''); ?></td>
                <?php
            } else if ($price == 0) {
                ?>
                <td><?php echo translate('Free of charge', 'montapacking-checkout') ?></td>
                <?php
            }
            ?>
        </tr>
        <?php

    }

    public static function get_shipping_total($data = null)
    {
        global $woocommerce;

        $items = $woocommerce->cart->get_cart();

        $hasDigitalProducts = false;
        $hasPhysicalProducts = false;
        foreach ($items as $item => $values) {
            $virtual = $values['data']->get_virtual();

            if ($virtual) {
                $hasDigitalProducts = true;
            } else {
                $hasPhysicalProducts = true;
            }
        }

        if ($hasPhysicalProducts == false && $hasDigitalProducts == true) {
            return 0;
        }

        if ($data === null) {
            if (isset($_POST['post_data'])) {
                parse_str(sanitize_post($_POST['post_data']), $data);
            }
        }

        $price = 0;

        $monta = null;
        $shipment = null;
        $pickup = null;
        $type = null;
        $time = null;
        $shipper = null;
        $extras = null;

        ## Postdata ophalen
        if (isset($data['montapacking'])) {

            $monta = $data['montapacking'];

            if (isset($monta['shipment'])) {
                $shipment = $monta['shipment'];
            }

            if (isset($monta['pickup'])) {
                $pickup = $monta['pickup'];
            }


            if (isset($shipment['type'])) {
                $type = $shipment['type'];
            }
            if (isset($shipment['time'])) {
                $time = $shipment['time'];
            }
            if (isset($shipment['shipper'])) {
                $shipper = $shipment['shipper'];
            }
            if (isset($shipment['extras'])) {
                $extras = $shipment['extras'];
            }


        }

        ## Check by type

        $isfound = false;
        if ($type == 'delivery') {

            ## Timeframes uit sessie ophalen
            $frames = $_SESSION['montapacking-frames'];
            if (is_array($frames)) {


                ## Check of gekozen timeframe bestaat
                if (isset($frames[$time])) {

                    $method = null;

                    ## Check of timeframe opties heeft
                    $frame = $frames[$time];
                    if (isset($frame->options)) {

                        ## Gekozen shipper ophalen
                        foreach ($frame->options as $option) {

                            if ($option->code == $shipper) {

                                $method = $option;
                                break;

                            }

                        }

                    }

                    ## Check of verzendmethode is gevonden
                    if ($method !== null) {

                        ## Basis prijs bepalen
                        $price += $method->price_raw;
                        $isfound = true;

                        ## Eventuele extra's bijvoeren
                        if (is_array($extras)) {

                            ## Extra's toeveogen
                            foreach ($extras as $extra) {

                                if (isset($method->extras[$extra])) {

                                    ## Extra bedrag toevoegen
                                    $price += $method->extras[$extra]->price_raw;

                                }

                            }

                        }

                    }

                }

            }

        } else if ($type == 'pickup') {
            $price = $pickup['price'];
            $isfound = true;
        }


        if (false === $isfound) {

            $start = esc_attr(get_option('monta_shippingcosts_start'));
            $default = esc_attr(get_option('monta_shippingcosts'));

            if (trim($start)) {
                return $start;
            } else {
                //return $default;
                return;
            }

        }


        return $price;

    }

    public static function shipping_options()
    {
        $type = sanitize_post($_POST['montapacking']);
        $shipment = $type['shipment'];

        switch ($shipment['type']) {
            case 'delivery':
                $frames = self::get_frames('delivery');

                if ($frames !== null) {

                    ## Frames naar handige array zetten
                    $items = self::format_frames($frames);
                    if ($items !== null) {

                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true,
                            'frames' => $items
                        ]);

                    } else {

                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false,
                            'message' => translate('3 - No shippers available for the chosen delivery address.', 'montapacking-checkout')
                        ]);

                    }

                } else {

                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => translate('4 - No shippers available for the chosen delivery address.', 'montapacking-checkout')
                    ]);
                }

                break;
            case 'pickup':

                #echo '<pre>';
                $frames = self::get_frames('pickup');
                if ($frames !== null) {

                    ## Frames naar handige array zetten
                    $items = self::format_pickups($frames);
                    #print_r($items);
                    if ($items !== null) {

                        ## Get order location
                        // Get lat and long by address
                        if (isset($_POST['ship_to_different_address']) && $_POST['ship_to_different_address'] == 1) {

                            $address = sanitize_text_field($_POST['shipping_address_1']) . ' ' .
                                sanitize_text_field($_POST['shipping_address_2']) . ' ' .
                                sanitize_text_field($_POST['shipping_postcode']) . ', ' .
                                sanitize_text_field($_POST['shipping_city']) . ' ' .
                                sanitize_text_field($_POST['shipping_country']) . '';

                        } else {

                            $address = sanitize_text_field($_POST['billing_address_1']) . ' ' .
                                sanitize_text_field($_POST['billing_address_2']) . ' ' .
                                sanitize_text_field($_POST['billing_postcode']) . ', ' .
                                sanitize_text_field($_POST['billing_city']) . ' ' .
                                sanitize_text_field($_POST['billing_country']) . '';

                        }
                        $prepAddr = str_replace('  ', ' ', $address);
                        $prepAddr = str_replace(' ', '+', $prepAddr);

                        $geocode = wp_remote_get('https://maps.google.com/maps/api/geocode/json?address=' . $prepAddr . '&sensor=false&key=' . esc_attr(get_option('monta_google_key')));
                        if (isset($geocode['body'])) {
                            $geocode = $geocode['body'];
                        }

                        $output = json_decode($geocode);

                        $result = end($output->results);
                        if (isset($result->geometry)) {
                            $latitude = $result->geometry->location->lat;
                            $longitude = $result->geometry->location->lng;
                        } else {
                            $latitude = 0;
                            $longitude = 0;
                        }

                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true,
                            'default' => (object)[
                                'lat' => $latitude,
                                'lng' => $longitude,
                            ],
                            'pickups' => $items
                        ]);

                    } else {

                        header('Content-Type: application/json');

                        echo json_encode([
                            'success' => false,
                            'message' => translate('No pickups available for the chosen delivery address.', 'montapacking-checkout')
                        ]);

                        //$logger = wc_get_logger();
                        //$context = array('source' => 'Montapacking Checkout WooCommerce Extension');
                        //$logger->notice('No pickups available for the chosen delivery address', $context);

                    }

                } else {

                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => translate('No pickups available for the chosen delivery address.', 'montapacking-checkout')
                    ]);

                    //$logger = wc_get_logger();
                    //$context = array('source' => 'Montapacking Checkout WooCommerce Extension');
                    //$logger->notice('No pickups available for the chosen delivery address', $context);

                }

                break;
            case 'collect':

                #echo '<pre>';
                $frames = self::get_frames('collect');
                if ($frames !== null) {

                    ## Frames naar handige array zetten
                    $items = self::format_pickups($frames);
                    #print_r($items);
                    if ($items !== null) {

                        ## Get order location
                        // Get lat and long by address
                        if (isset($_POST['ship_to_different_address']) && $_POST['ship_to_different_address'] == 1) {

                            $address = sanitize_text_field($_POST['shipping_address_1']) . ' ' .
                                sanitize_text_field($_POST['shipping_address_2']) . ' ' .
                                sanitize_text_field($_POST['shipping_postcode']) . ', ' .
                                sanitize_text_field($_POST['shipping_city']) . ' ' .
                                sanitize_text_field($_POST['shipping_country']) . '';

                        } else {

                            $address = sanitize_text_field($_POST['billing_address_1']) . ' ' .
                                sanitize_text_field($_POST['billing_address_2']) . ' ' .
                                sanitize_text_field($_POST['billing_postcode']) . ', ' .
                                sanitize_text_field($_POST['billing_city']) . ' ' .
                                sanitize_text_field($_POST['billing_country']) . '';

                        }
                        $prepAddr = str_replace('  ', ' ', $address);
                        $prepAddr = str_replace(' ', '+', $prepAddr);

                        $geocode = wp_remote_get('https://maps.google.com/maps/api/geocode/json?address=' . $prepAddr . '&sensor=false&key=' . esc_attr(get_option('monta_google_key')));
                        if (isset($geocode['body'])) {
                            $geocode = $geocode['body'];
                        }

                        $output = json_decode($geocode);

                        $result = end($output->results);
                        if (isset($result->geometry)) {
                            $latitude = $result->geometry->location->lat;
                            $longitude = $result->geometry->location->lng;
                        } else {
                            $latitude = 0;
                            $longitude = 0;
                        }

                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true,
                            'default' => (object)[
                                'lat' => $latitude,
                                'lng' => $longitude,
                            ],
                            'pickups' => $items
                        ]);

                    } else {

                        header('Content-Type: application/json');

                        echo json_encode([
                            'success' => false,
                            'message' => translate('No pickups available for the chosen delivery address.', 'montapacking-checkout')
                        ]);

                        //$logger = wc_get_logger();
                        //$context = array('source' => 'Montapacking Checkout WooCommerce Extension');
                        //$logger->notice('No pickups available for the chosen delivery address', $context);

                    }

                } else {

                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => translate('No pickups available for the chosen delivery address.', 'montapacking-checkout')
                    ]);

                    //$logger = wc_get_logger();
                    //$context = array('source' => 'Montapacking Checkout WooCommerce Extension');
                    //$logger->notice('No pickups available for the chosen delivery address', $context);

                }

                break;
        }

        wp_die();

    }

    public static function get_frames($type = 'delivery')
    {

        global $woocommerce;

        ## Postdata escapen
        $data = sanitize_post($_POST);
        foreach ($data as $field => $value) {
            $data[$field] = ($value != '' && $value != null && !is_array($value)) ? htmlspecialchars($value) : $value;
        }
        $data = (object)$data;

        if (isset($data->billing_street_name) && trim($data->billing_street_name)) {
            $data->billing_address_1 = $data->billing_street_name;

            if (trim($data->billing_house_number)) {
                $data->billing_address_1 = $data->billing_address_1 . " " . $data->billing_house_number;
            }
        }

        if ($type == 'delivery') {

            $api = new MontapackingShipping(esc_attr(get_option('monta_shop')), esc_attr(get_option('monta_username')), esc_attr(get_option('monta_password')), false);


        } else if ($type == 'pickup' || $type == 'collect') {
            $api = new MontapackingShipping(esc_attr(get_option('monta_shop')), esc_attr(get_option('monta_username')), esc_attr(get_option('monta_password')), esc_attr(get_option('monta_google_key')));
        }

        ## Monta packing API aanroepen
        if (!isset($data->ship_to_different_address)) {
            ## Set address of customer
            $api->setAddress(
                $data->billing_address_1,
                '',
                $data->billing_address_2,
                $data->billing_postcode,
                $data->billing_city,
                $data->billing_state,
                $data->billing_country
            );
        } else {
            ## Set shipping adres of customer when different
            $api->setAddress(
                $data->shipping_address_1,
                '',
                $data->shipping_address_2,
                $data->shipping_postcode,
                $data->shipping_city,
                $data->shipping_state,
                $data->shipping_country
            );
        }

        ## Fill products
        $items = $woocommerce->cart->get_cart();

        $bAllProductsAvailableAtMontapacking = true;
        $bAllProductsAvailableAtWooCommerce = true;

        $hasDigitalProducts = false;
        $hasPhysicalProducts = false;

        $skuArray = array();
        $x=0;

        foreach ($items as $item => $values) {
            $product = wc_get_product($values['product_id']);
            if($values['variation_id']){
                $product = wc_get_product($values['variation_id']);
            }
            if($product->get_type() != "woosb"){
                $sku = $product->get_sku();
                $quantity = isset($values['quantity']) ? $values['quantity'] : 1;

                if (trim($sku))
                {
                    $skuArray[$x] = array($sku, $quantity);
                    $x++;
                }

                $virtual = $product->get_virtual();

                if ($virtual) {
                    $hasDigitalProducts = true;
                } else {
                    $hasPhysicalProducts = true;

                    $stockstatus = $product->get_stock_status();

                    if ($stockstatus != 'instock') {
                        $bAllProductsAvailableAtWooCommerce = false;
                    }
                }
            }
        }

        if ($hasPhysicalProducts == false && $hasDigitalProducts == true) {
            return null;
        }

        $subtotal = (WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax());
        $subtotal_ex = WC()->cart->get_subtotal_tax();

        $api->setOrder($subtotal, $subtotal_ex);

        if (esc_attr(get_option('monta_logerrors'))) {
            $logger = wc_get_logger();
            $api->setLogger($logger);

        }

        ## Type timeframes ophalen
        if (esc_attr(get_option('monta_leadingstock')) == 'woocommerce') {
            $bStockStatus = $bAllProductsAvailableAtWooCommerce;
        } else {
            $bStockStatus = $bAllProductsAvailableAtMontapacking;
        }
        do_action( 'woocommerce_cart_shipping_packages' );

        if ($type == 'delivery') {
            if (esc_attr(get_option('monta_checkproductsonsku'))) {
                $shippingOptions = $api->getShippingOptions($bStockStatus, false, false, false, false, $skuArray);
            }
            else
            {
                $shippingOptions = $api->getShippingOptions($bStockStatus);
            }
            if (esc_attr(get_option('monta_shippingcosts_fallback_woocommerce'))) {
                if ($shippingOptions != null && $shippingOptions[0]->code == 'Monta' && $shippingOptions[0]->description == 'Monta'){
                    foreach ($shippingOptions[0]->options as $option) {
                        $option->price = self::$WooCommerceShippingMethod['cost'];
                    }
                }
            }
            return $shippingOptions;
        } else if ($type == 'pickup') {
            if (esc_attr(get_option('monta_checkproductsonsku'))) {
                return $api->getPickupOptions($bStockStatus, false, false, false, false, $skuArray);
            }
            else
            {
                return $api->getPickupOptions($bStockStatus);
            }
        } else if ($type == 'collect') {
            if (esc_attr(get_option('monta_checkproductsonsku'))) {
                return $api->getPickupOptions($bStockStatus, false, false, false, false, $skuArray, true);
            } else {
                return $api->getPickupOptions($bStockStatus, false, false, false, false, array(), true);
            }
        }
    }

    public static function calculateExtras($extra_values = null, $curr = '&euro;')
    {
        $freeShippingCouponCode = self::checkFreeShippingCouponCodes();

        $extras = null;
        if (count($extra_values) > 0) {
            foreach ($extra_values as $extra) {
                ## Extra optie toevoegen
                if ($freeShippingCouponCode) {
                    $extra->price = 0;
                }

                $extras[$extra->code] = (object)[
                    'code' => $extra->code,
                    'name' => $extra->name,
                    'price' => $curr . ' ' . number_format($extra->price, 2, ',', ''),
                    'price_raw' => $extra->price,
                ];
            }
        }

        return $extras;
    }

    public static function format_frames($frames)
    {
        $items = array();

        $curr = '&euro;';
        
        $freeShippingCouponCode = self::checkFreeShippingCouponCodes();

        //create days
        if (is_array($frames) || is_object($frames)) {

            foreach ($frames as $nr => $frame) {

                if ($frame->type == 'ShippingDay') {

                    foreach ($frame->options as $onr => $option) {
                        $key = strtotime(date("Y-m-d", strtotime($option->date)));
                        $from = date('d-m-Y', strtotime($option->date));

                        if (!isset($items[$key])) {
                            $items[$key] = (object)[
                                'code' => $frame->code,
                                'date' => $from,
                                'datename' => translate(date("l", $key)),
                                'description' => '',
                                'options' => array(),
                            ];
                        }

                    }
                }

                if ($frame->type == 'DeliveryDay') {

                    $key = strtotime(date("Y-m-d", strtotime($frame->from)));
                    $from = date('d-m-Y', strtotime($frame->from));

                    if (!isset($items[$key])) {
                        $items[$key] = (object)[
                            'code' => $frame->code,
                            'date' => $from,
                            'datename' => translate(date("l", $key)),
                            'description' => $frame->description,
                            'options' => array(),
                        ];
                    }

                }

                if ($frame->type == 'Unknown') {

                    foreach ($frame->options as $onr => $option) {
                        $key = "NOTIMES";
                        $from = '';

                        if ($option->date != null && strtotime($option->date) > 0) {
                            $key = strtotime(date("Y-m-d", strtotime($option->date)));
                            $from = date('d-m-Y', strtotime($option->date));

                        } elseif ($option->code == 'RED_ShippingDayUnknown') {
                            $key = strtotime(date("Y-m-d"));
                            $from = date('d-m-Y', time());
                        }

                        if (!isset($items[$key])) {

                            $dateName = "";
                            if($key != "NOTIMES")
                            {
                                $dateName = translate(date("l", $key));
                            }

                            $items[$key] = (object)[
                                'code' => $frame->code,
                                'date' => $from,
                                'datename' => $dateName,
                                'description' => $frame->description,
                                'options' => array(),
                            ];
                        }
                    }
                }
            }
        }
        ksort($items);


        // sort options to days

        if (is_array($frames) || is_object($frames)) {

            foreach ($frames as $nr => $frame) {

                if ($frame->from != '' && $frame->to != '') {

                    $key = strtotime(date("Y-m-d", strtotime($frame->from)));

                    foreach ($frame->options as $onr => $option) {
                        $from = $option->from;
                        $to = $option->to;

                        $extras = null;
                        if (isset($option->extras)) {
                            $extras = self::calculateExtras($option->extras, $curr);
                        }

                        $evening = '';
                        if (count($option->optioncodes)) {
                            foreach ($option->optioncodes as $optioncode) {

                                if ($optioncode == 'EveningDelivery') {
                                    $evening = " (" . translate('evening delivery', 'montapacking-checkout') . ") ";
                                }
                            }
                        }

                        if ($freeShippingCouponCode) {
                            $option->price = 0;
                        }

                        $options_object = (object)[
                            'code' => $option->code,
                            'codes' => $option->codes,
                            'optionCodes' => $option->optioncodes,
                            'name' => $option->description . $evening,
                            'displayname' => $option->displayname . $evening,
                            'is_preferred' => $option->isPreferred,
                            'is_sustainable' => $option->isSustainable,
                            'ships_on' => '',
                            'type' => 'deliverydate',
                            'type_text' => translate('delivered', 'montapacking-checkout'),
                            'price' => $curr . ' ' . number_format($option->price, 2, ',', ''),
                            'price_raw' => $option->price,
                            'from' => date('H:i', strtotime($from . ' +1 hour')),
                            'to' => date('H:i', strtotime($to . ' +1 hour')),
                            'extras' => $extras,
                            'request_url' => $frame->requesturl,
                            'discount_percentage' => $option->discountPercentage
                        ];

                        if (isset($items[$key]) && (time() + 3600) <= strtotime($option->date)) {
                            $items[$key]->options[] = $options_object;
                        }
                    }

                }
            }

            foreach ($frames as $nr => $frame) {

                if ($frame->type == 'ShippingDay') {

                    foreach ($frame->options as $onr => $option) {

                        $key = strtotime(date("Y-m-d", strtotime($option->date)));

                        $from = $option->date;
                        $to = $option->date;

                        $extras = null;
                        if (isset($option->extras)) {
                            $extras = self::calculateExtras($option->extras, $curr);
                        }

                        if ($freeShippingCouponCode) {
                            $option->price = 0;
                        }

                        $options_object = (object)[
                            'code' => $option->code,
                            'codes' => $option->codes,
                            'optionCodes' => $option->optioncodes,
                            'name' => $option->description,
                            'displayname' => $option->displayname,
                            'is_preferred' => $option->isPreferred,
                            'is_sustainable' => $option->isSustainable,
                            'ships_on' => "(" . translate('ships on', 'montapacking-checkout') . " " . date("d-m-Y", strtotime($option->date)) . " " . translate('from the Netherlands', 'montapacking-checkout') . ")",
                            'type' => 'shippingdate',
                            'type_text' => translate('shipped', 'montapacking-checkout'),
                            'price' => $curr . ' ' . number_format($option->price, 2, ',', ''),
                            'price_raw' => $option->price,
                            'from' => date('H:i', strtotime($from . ' +1 hour')),
                            'to' => date('H:i', strtotime($to . ' +1 hour')),
                            'extras' => $extras,
                            'request_url' => $frame->requesturl,
                            'discount_percentage' => $option->discountPercentage
                        ];

                        $allow = true;
                        if (date("Y-m-d", $key) == date("Y-m-d")) {
                            $allow = false;
                        }
                        if (true === $allow) {

                            if ((time() + 3600) <= strtotime($option->date)) {
                                $items[$key]->options[] = $options_object;
                            }
                        }

                    }
                }
            }
            foreach ($frames as $nr => $frame) {
                if ($frame->type == 'Unknown') {
                    foreach ($frame->options as $onr => $option) {
                        $key = "NOTIMES";
                        $desc = $option->description;
                        $ships_on = '';
                        $type = 'deliverydate';
                        $type_text = 'delivered';

                        if ($option->date != null && strtotime($option->date) > 0) {
                            $key = strtotime(date("Y-m-d", strtotime($option->date)));
                            $desc = $option->description;
                            $ships_on = "";
                            $type = 'shippingdate';
                            $type_text = 'shipped';
                        } elseif ($option->code == 'RED_ShippingDayUnknown') {
                            $key = strtotime(date("Y-m-d"));
                            $desc = 'Red je pakket';
                        } elseif ($option->code == 'Trunkrs_ShippingDayUnknown') {
                            $key = strtotime(date("Y-m-d"));
                            $desc = 'Red je pakket';
                        }

                        $extras = null;
                        if (isset($option->extras)) {
                            $extras = self::calculateExtras($option->extras, $curr);
                        }

                        if ($freeShippingCouponCode) {
                            $option->price = 0;
                        }

                        $options_object = (object)[
                            'codes' => $option->codes,
                            'code' => $option->code,
                            'name' => $desc,
                            'displayname' => $option->displayname,
                            'is_preferred' => $option->isPreferred,
                            'is_sustainable' => $option->isSustainable,
                            'ships_on' => $ships_on,
                            'type' => $type,
                            'type_text' => translate($type_text, 'montapacking-checkout'),
                            'price' => $curr . ' ' . number_format($option->price, 2, ',', ''),
                            'price_raw' => $option->price,
                            'from' => null,
                            'to' => null,
                            'extras' => $extras,
                            'request_url' => $frame->requesturl,
                            'discount_percentage' => $option->discountPercentage
                        ];

                        $allow = true;
                        if ($key != "NOTIMES" && date("Y-m-d", $key) == date("Y-m-d") && $frame->code != 'SameDayDelivery') {
                            $allow = false;
                        }

                        if ($option->code == "MultipleShipper_ShippingDayUnknown")
                        {
                            $allow = true;
                        }

                        if (true === $allow) {
                            if (((time() + 3600) <= strtotime($option->date)) || ($key == 'NOTIMES')) {
                                $items[$key]->options[] = $options_object;
                            }
                        }
                    }
                }
            }
        }

        $cleared_items = array();
        foreach ($items as $key => $item) {
            if (count($item->options) > 0) {
                $cleared_items[$key] = $item;
            }
        }
        $items = $cleared_items;

        ## Frames opslaan in sessie voor bepalen kosten
        $_SESSION['montapacking-frames'] = $items;

        //$_SESSION['montapacking-frames-test'] = $items;
        return $items;
    }

    public static function format_pickups($frames)
    {

        $items = null;

        $freeShippingCouponCode = self::checkFreeShippingCouponCodes();

        ## Check of er meerdere timeframes zijn, wanneer maar één dan enkel shipper keuze zonder datum/tijd
        if (is_array($frames) || is_object($frames)) {

            foreach ($frames as $nr => $frame) {

                ## Loop trough options
                $selected = null;

                ## Pickup optie ophalen
                $option = end($frame->options);
                if (isset($option->codes)) {

                    $extras = null;
                    if (isset($option->extras) && count($option->extras) > 0) {

                        foreach ($option->extras as $extra) {

                            if ($freeShippingCouponCode) {
                                $extra->price = 0;
                            }

                            ## Extra optie toevoegen
                            $extras[$extra->code] = (object)[
                                'code' => $extra->code,
                                'name' => $extra->name,
                                'price' => number_format($extra->price, 2, ',', ''),
                                'price_raw' => $extra->price,
                            ];

                        }

                    }

                    ## Check of er een prijs is
                    if ($option !== null) {

                        // Maak een string van alle shipperoptions
                        $shipperOptions = "";
                        foreach ($option->optionsWithValue as $key => $value) {
                            $shipperOptions .= $key . "_" . $value . ",";
                        }
                        $shipperOptions = rtrim($shipperOptions, " ,");

                        if ($freeShippingCouponCode) {
                            $option->price = 0;
                        }

                        $items[$nr] = (object)[
                            'code' => implode(',', $option->codes),
                            'date' => date('d-m-Y', strtotime($option->date)),
                            'time' => (date('H:i', $frame->from != null && $frame->to != null && strtotime($frame->from)) != date('H:i', strtotime($frame->to))) ? date('H:i', strtotime($frame->from)) . '-' . date('H:i', strtotime($frame->to)) : '',
                            'description' => $option->description,
                            'displayname' => $option->displayname,
                            'is_preferred' => $option->isPreferred,
                            'is_sustainable' => $option->isSustainable,
                            'details' => $frame->details,
                            'shipperOptionsWithValue' => $shipperOptions,
                            'price' => number_format($option->price, 2, ',', ''),
                            'price_raw' => $option->price,
                            'request_url' => $frame->requesturl
                        ];

                        ## Sorteer opties op laagste prijs
                        usort($items, function ($a, $b) {
                            return $a->price_raw - $b->price_raw;
                        });

                    }

                }

                #}

            }

        }

        ## Frames opslaan in sessie voor bepalen kosten
        $_SESSION['montapacking-pickups'] = $items;

        return $items;

    }

    public static function shipping()
    {

        include 'views/choice.php';

    }

    public static function taxes()
    {


        $value = '<strong>' . WC()->cart->get_total() . '</strong> ';

        // If prices are tax inclusive, show taxes here.
        if (wc_tax_enabled() && WC()->cart->display_prices_including_tax()) {
            $tax_string_array = array();
            $cart_tax_totals = WC()->cart->get_tax_totals();

            if (get_option('woocommerce_tax_total_display') === 'itemized') {
                foreach ($cart_tax_totals as $code => $tax) {
                    $tax_string_array[] = sprintf('%s %s', $tax->formatted_amount, $tax->label);
                }
            } elseif (!empty($cart_tax_totals)) {


                $mixed = WC_Tax::get_shipping_tax_rates(null, null);

                $found = false;
                $vat_percent = 0;
                $id = "";
                foreach ($mixed as $key => $obj) {
                    $vat_percent = $obj['rate'];
                    $found = true;
                    $id = ":" . $key;
                    break;
                }


                $shipping_costs = self::shipping_total();
                $tax = ($shipping_costs / 121) * $vat_percent;

                $vat_ex_shipment = WC()->cart->get_taxes_total(true, true);

                $price = wc_price($vat_ex_shipment + $tax);


                $tax_string_array[] = sprintf('%s %s', $price, WC()->countries->tax_or_vat());
            }

            if (!empty($tax_string_array)) {
                $taxable_address = WC()->customer->get_taxable_address();
                /* translators: %s: country name */
                $estimated_text = WC()->customer->is_customer_outside_base() && !WC()->customer->has_calculated_shipping() ? sprintf(' ' . __('estimated for %s', 'woocommerce'), WC()->countries->estimated_for_prefix($taxable_address[0]) . WC()->countries->countries[$taxable_address[0]]) : '';
                $value .= '<small class="includes_tax">'
                    /* translators: includes tax information */
                    . sprintf( __( '(includes %s)', 'woocommerce' ), implode( ', ', $tax_string_array ) )
                    . esc_html($estimated_text)
                    . '</small>';
            }
        }

        return $value;
    }

    public static function checkFreeShippingCouponCodes()
    {
        global $woocommerce;
        if ($woocommerce->cart->get_applied_coupons()) {
            foreach ($woocommerce->cart->get_applied_coupons() as $coupon) {
                $getDetails = (new WC_Coupon($coupon));

                if ($getDetails->get_free_shipping()) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param $package
     * @return array|null
     */
    public static function getWooCommerceShippingMethod($package): ?array
    {
        if($package == ""){
            $address = array();
            $address['destination'] = Array();
            $address['destination']['country'] = WC()->customer->get_shipping_country();
            $address['destination']['state'] = WC()->customer->get_shipping_state();
            $address['destination']['postcode'] = WC()->customer->get_shipping_postcode();
            $shipping_zone = WC_Shipping_Zones::get_zone_matching_package($address);
        } else {
            $shipping_zone = WC_Shipping_Zones::get_zone_matching_package($package);
        }
        $chosenMethod = null;
        foreach ($shipping_zone->get_shipping_methods(true) as $key => $class) {
            // Method's ID and custom name
            $item = [
                'id' => $class->method_title,
                'name' => $class->title,
                'cost' => $class->instance_settings["cost"],
                'requires' => $class->requires
            ];

            // If minimum amount is required
            if (isset($class->min_amount) && $class->min_amount > 0) $item['minimum'] = (float)$class->min_amount;

            if ($chosenMethod == null || ($chosenMethod['cost'] != null && (float)$chosenMethod['cost'] > (float)$item['cost'])) {
                if (!isset($item['requires']) || $item["requires"] == "") {
                    $chosenMethod = $item;
                } else if ($item['requires'] == 'min_amount' && WC()->cart->get_cart_contents_total() >= (float)$item['minimum']) {
                    $chosenMethod = $item;
                } else if ($item['requires'] == 'both' || $item['requires'] == 'coupon' || $item['requires'] == 'either') {
                    $applied_coupons = WC()->cart->get_applied_coupons();
                    $hasFreeShipping = false;
                    foreach ($applied_coupons as $coupon_code) {
                        $coupon = new WC_Coupon($coupon_code);
                        if ($coupon->get_free_shipping()) {
                            $hasFreeShipping = true;
                        }
                    }
                    if ($hasFreeShipping) {
                        if ($item['requires'] == 'coupon' || (WC()->cart->get_cart_contents_total() >= (float)$item['minimum'])) {
                            $chosenMethod = $item;
                        }
                    } else {
                        if($item['requires'] == 'either' || (WC()->cart->get_cart_contents_total() >= (float)$item['minimum'])){
                            $chosenMethod = $item;
                        }
                    }
                }
            }
        }
        return $chosenMethod;
    }

}
