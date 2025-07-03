<?php

/**
 * Plugin Name: DD WooCommerce Delayed Subscription Payment
 * Description: Delays the first WooCommerce subscription payment to Sunday if the user subscribes on a Friday or Saturday.
 * Version:     6.0.0
 * Author:      DD DevOps
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Subscription_Payment_Delay
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('woocommerce_checkout_subscription_created', [$this, 'delay_subscription_payment'], 10, 2);
        add_filter('woocommerce_order_needs_payment', [$this, 'disable_payment_processing'], 10, 2);
        add_filter('wc_stripe_generate_create_intent_request', [$this, 'manual_stripe_generate_create_intent_request'], 10, 2);
        add_filter('woocommerce_subscription_payment_failed', [$this, 'update_subscription_status_on_payment'], 10, 1);
        add_action('process_delayed_payments_hook', [$this, 'process_delayed_payments']);
        add_action('template_redirect', [$this, 'delayed_payment_confirmation_redirect']);
        add_action('wp_footer', [$this, 'delayed_payment_popup']);
    }

    public function add_settings_page()
    {
        add_submenu_page(
            'woocommerce',
            'Subscription Payment Delay',
            'Subscription Delay',
            'manage_options',
            'wc_subscription_payment_delay',
            [$this, 'settings_page_content']
        );
    }

    public function register_settings()
    {
        register_setting('wc_subscription_payment_delay_settings', 'wc_delay_subscription_enabled');
        add_settings_section('wc_subscription_delay_section', 'Subscription Payment Delay Settings', null, 'wc_subscription_payment_delay');
        add_settings_field(
            'wc_delay_subscription_enabled',
            'Enable Payment Delay',
            [$this, 'enable_delay_callback'],
            'wc_subscription_payment_delay',
            'wc_subscription_delay_section'
        );
    }

    public function enable_delay_callback()
    {
        $value = get_option('wc_delay_subscription_enabled', 'no');
        echo '<input type="checkbox" name="wc_delay_subscription_enabled" value="yes" ' . checked($value, 'yes', false) . ' />';
    }

    public function settings_page_content()
    {
        echo '<div class="wrap"><h1>Subscription Payment Delay</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('wc_subscription_payment_delay_settings');
        do_settings_sections('wc_subscription_payment_delay');
        submit_button();
    }

    public function delay_subscription_payment($subscription, $order)
    {
        if (get_option('wc_delay_subscription_enabled') !== 'yes') {
            return;
        }

        $current_day = gmdate('w'); // 0 = Sunday, 6 = Saturday
        if ($current_day == 5 || $current_day == 6) { // Only delay if it's Friday or Saturday
            $next_sunday = strtotime('next Sunday');

            foreach ($subscription->get_items() as $item) {

                $product_id = $item->get_product_id();

                if (has_term('plan', 'product_cat', $product_id)) {

                    $subscription->update_dates([
                        'trial_end'    => gmdate('Y-m-d H:i:s', $next_sunday),
                        // 'next_payment' => gmdate('Y-m-d H:i:s', $next_sunday),
                    ]);

                    update_post_meta($subscription->get_id(), '_requires_manual_renewal', 'true');
                    update_post_meta($subscription->get_id(), '_delayed_payment', 'true');
                    update_post_meta($subscription->get_id(), '_stripe_intent_capture_method', 'manual');

                    $order->update_meta_data('_delayed_payment', 'true');
                    $order->update_meta_data('_delayed_payment_schedule', gmdate('Y-m-d H:i:s', $next_sunday));

                    $subscription->set_status('active');
                    $subscription->save();
                    $order->save();
                }
            }
        }
    }

    public function disable_payment_processing($needs_payment, $order)
    {
        if (get_option('wc_delay_subscription_enabled') !== 'yes') {
            return $needs_payment;
        }
        return $needs_payment;
    }

    public function manual_stripe_generate_create_intent_request($request, $order)
    {
        if (get_option('wc_delay_subscription_enabled') !== 'yes') {
            return $request;
        }

        // Ensure it's a new subscription, not a renewal
        if (wcs_order_contains_renewal($order->get_id())) {
            return $request;
        }

        $current_day = gmdate('w');
        if ($current_day == 5 || $current_day == 6) { // Only on Friday or Saturday
            $subscriptions = wcs_get_subscriptions_for_order($order->get_id());
            if (!empty($subscriptions)) {
                foreach ($subscriptions as $subscription) {
                    foreach ($subscription->get_items() as $item) {

                        $product_id = $item->get_product_id();

                        if (has_term('plan', 'product_cat', $product_id)) {

                            $request['capture_method'] = 'manual';
                            $next_sunday = strtotime('next Sunday');

                            // $subscription->update_dates([
                            //     'next_payment' => gmdate('Y-m-d H:i:s', $next_sunday),
                            // ]);
                            $subscription->update_status('active');
                            $subscription->save();
                            $this->send_email_delayed_payment($subscription, $order->get_id(), gmdate('Y-m-d', $next_sunday));
                        }
                    }
                }
            }

            $order->update_meta_data('_delayed_payment', 'true');
            $order->update_meta_data('_delayed_payment_schedule', gmdate('Y-m-d H:i:s', $next_sunday));
            $order->save();

            $orderData = $order->get_data();
            $orderItems = $order->get_items();
            $orderItemsGroup = [];
            $productType = "";
            $notificationFinalMsg = "";
            $additionalDesignerCurrentIndex = countAdditionalDesignerByUser($orderData['customer_id']);

            foreach ($orderItems as $item_id => $item) {
                $itemName = $item->get_name();

                if (has_term('active-task', 'product_cat', $item->get_product_id())) {
                    $productType = 'Product';
                    $orderItemsGroup[] = $itemName . " ($additionalDesignerCurrentIndex)";
                } else if (has_term('add-on', 'product_cat', $item->get_product_id())) {
                    $productType = 'Add on';
                    $orderItemsGroup[] = $itemName;
                } else {
                    $productType = 'Plan';
                    $notificationFinalMsg = 'Let\'s wait for the onboarding rocket :muscle::skin-tone-2:';
                    $orderItemsGroup[] = $itemName;
                }
            }

            $customerName = $orderData['billing']['first_name'] . ' ' . $orderData['billing']['last_name'];
            $customerEmail = $orderData['billing']['email'];
            $customerCompany = $orderData['billing']['company'];
            $orderItemsGroup = implode(" | ", $orderItemsGroup);

            $slackMessage = "We have a new subscription from weekend sign up, <!channel> :smiling_face_with_3_hearts:\n*Client:* $customerName | $customerEmail\n*$productType:* $orderItemsGroup\n$notificationFinalMsg";

            $slackMessageBody = [
                "text" => $slackMessage,
                "username" => "Devops"
            ];

            $this->slackNotificationsDelayed($slackMessageBody);
        }

        return $request;
    }


    public function update_subscription_status_on_payment($subscription)
    {
        if (get_option('wc_delay_subscription_enabled') !== 'yes') {
            return;
        }

        $current_day = gmdate('w'); // 0 = Sunday, 6 = Saturday
        if ($current_day == 5 || $current_day == 6) { // Only delay if it's Friday or Saturday

            foreach ($subscription->get_items() as $item) {

                $product_id = $item->get_product_id();

                if (has_term('plan', 'product_cat', $product_id)) {
                    $subscription->update_status('active');
                    $subscription->save();
                }
            }
        }
    }

    public function process_delayed_payments()
    {
        global $wpdb;

        $today = current_time('Y-m-d');
        $prepared_query = $wpdb->prepare("
            SELECT p.ID, p.post_type, p.post_date,
                dpm.meta_value AS delayed_payment_schedule,
                dp.meta_value AS delayed_payment,
                rmeta.meta_value AS subscription_renewal
            FROM {$wpdb->posts} AS p
            LEFT JOIN {$wpdb->postmeta} AS dpm ON p.ID = dpm.post_id AND dpm.meta_key = '_delayed_payment_schedule'
            LEFT JOIN {$wpdb->postmeta} AS dp ON p.ID = dp.post_id AND dp.meta_key = '_delayed_payment'
            LEFT JOIN {$wpdb->postmeta} AS rmeta ON p.ID = rmeta.post_id AND rmeta.meta_key = '_subscription_renewal'
            WHERE p.post_type = 'shop_order'
            AND dp.meta_value = 'true'
            AND dpm.meta_value IS NOT NULL
            AND DATE(dpm.meta_value) = %s
        ", $today);

        $query = $wpdb->get_results($prepared_query);

        foreach ($query as $post) {
            $subscriptions = [];
            $order_id = $post->ID;
            $order_creation_time = strtotime($post->post_date);
            $parent_subscription_id = !empty($post->subscription_renewal) ? (int) $post->subscription_renewal : null;

            // Convert delayed_payment_schedule to timestamp only if it exists
            $delayed_payment_schedule = !empty($post->delayed_payment_schedule) ? strtotime($post->delayed_payment_schedule) : null;

            // if ($post->post_type === 'shop_subscription') {
            //     $subscriptions = [wcs_get_subscription($order_id)];
            //     $end_date = null;
            //     $payment_order_id = null;
            // }
            // else {
            if (!empty($parent_subscription_id)) {
                $subscriptions = [wcs_get_subscription($parent_subscription_id)];
                $end_date = $delayed_payment_schedule ?: $order_creation_time;
                $payment_order_id = $order_id;
            } else {
                $subscriptions = wcs_get_subscriptions_for_order($order_id);
                $end_date = $order_creation_time;
                $payment_order_id = $order_id; // Default to current order ID
            }
            // }

            foreach ($subscriptions as $subscription) {
                if ($subscription) {
                    // $trial_end = strtotime($subscription->get_date('trial_end'));
                    $current_time = current_time('timestamp');

                    // Override trial_end only for orders, not subscriptions
                    // if ($end_date) {
                    //     $trial_end = $end_date;
                    // }

                    if ($end_date && $current_time >= $end_date) {
                        // Process payment only if time has passed
                        if (!empty($payment_order_id)) {
                            $this->update_subscription_status($subscription);
                            $this->capture_delayed_payment($payment_order_id, $subscription);
                        }
                    }
                }
            }
        }
    }



    public function update_subscription_status($subscription)
    {
        if (get_option('wc_delay_subscription_enabled') !== 'yes') {
            return;
        }

        if (is_int($subscription)) {
            $subscription = wcs_get_subscription($subscription);
        }

        if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
            return;
        }

        $was_delayed = get_post_meta($subscription->get_id(), '_requires_manual_renewal', true);
        if ($was_delayed !== 'true') {
            return;
        }

        $order_id = $subscription->get_parent_id();
        $order = wc_get_order($order_id);
        if (!$order) {
            $order->add_order_note('update_subscription_status: No parent order found for Subscription ID ' . $subscription->get_id());
        } else {
            $order->add_order_note('update_subscription_status: Subscription updated to automatic payments.');
        }

        $subscription->set_requires_manual_renewal(false);
        $subscription->update_status('active');
        $subscription->update_meta_data('_schedule_trial_end', '0');
        delete_post_meta($subscription->get_id(), '_delayed_payment');
        $subscription->save();

        $subscription->add_order_note('update_subscription_status: Subscription ID ' . $subscription->get_id() . ' updated to automatic payments.');
    }

    public function capture_delayed_payment($order_id, $subscription)
    {
        $order = wc_get_order($order_id);

        if (! $order instanceof WC_Order) {
            return;
        }
        $payment_intent_id = get_post_meta($order_id, '_stripe_intent_id', true);
        if ($payment_intent_id) {
            \Stripe\Stripe::setApiKey(STRIPE_API);

            try {
                $intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
                $intent->capture();

                if ($order) {
                    $next_payment_timestamp = $subscription->get_time('next_payment') ?: strtotime('+1 month');
                    $next_payment_date = gmdate('Y-m-d H:i:s', $next_payment_timestamp);
                    // $next_month = strtotime('+1 month'); // Next month timestamp
                    // $next_payment_date = gmdate('Y-m-d H:i:s', $next_month); // Format for WooCommerce
                    $order->payment_complete($payment_intent_id);
                    $order->delete_meta_data('_delayed_payment');
                    $order->delete_meta_data('_delayed_payment_schedule');

                    $order->add_order_note("capture_delayed_payment: Payment Intent $payment_intent_id captured successfully.");
                    $order->save();

                    update_post_meta($subscription->get_id(), '_stripe_charge_captured', 'yes');
                    // update_post_meta($subscription->get_id(), '_delayed_payment', 'false');
                    delete_post_meta($subscription->get_id(), '_delayed_payment');
                    update_post_meta($subscription->get_id(), '_schedule_trial_end', '0');
                    update_post_meta($subscription->get_id(), '_schedule_next_payment', $next_payment_date);
                    $subscription->add_order_note("capture_delayed_payment: Payment Intent $payment_intent_id captured successfully.");

                    // Send successful payment email
                    $chargeDate = gmdate('Y-m-d'); // Current date
                    $this->send_email_successful_payment($subscription, $chargeDate, $order_id);

                    $orderData = $order->get_data();
                    $customerName = $orderData['billing']['first_name'] . ' ' . $orderData['billing']['last_name'];
                    $customerCompany = $orderData['billing']['company'];

                    $slackMessage = "<!channel> :white_check_mark: *$customerName* ($customerCompany) payment received from weekend sign up.";

                    $slackMessageBody = [
                        "text" => $slackMessage,
                        "username" => "Devops"
                    ];

                    $this->slackNotificationsDelayed($slackMessageBody);
                }
            } catch (\Exception $e) {
                $order->add_order_note("Error capturing payment: " . $e->getMessage());
                $subscription->add_order_note("Error capturing payment: " . $e->getMessage());
            }
        }
    }

    public function delayed_payment_confirmation_redirect()
    {
        if (is_admin() || ! is_user_logged_in()) {
            return;
        }

        global $wp;

        $current_day = gmdate('w'); // 0 = Sunday, 6 = Saturday
        if ($current_day == 5 || $current_day == 6) { // Only delay if it's Friday or Saturday
            if ((strpos($wp->request, 'sign-up/order-pay/') !== false && isset($_GET['pay_for_order']) && $_GET['pay_for_order'] === 'true') || (strpos($wp->request, 'sign-up/order-received/') !== false && isset($_GET['key']))) {
                // Extract order ID correctly
                $order_id = 0;

                if (strpos($wp->request, 'sign-up/order-pay/') !== false) {
                    $order_id = absint(get_query_var('order-pay'));
                } elseif (strpos($wp->request, 'sign-up/order-received/') !== false) {
                    preg_match('/sign-up\/order-received\/(\d+)/', $wp->request, $matches);
                    if (!empty($matches[1])) {
                        $order_id = absint($matches[1]);
                    }
                }

                if (! $order_id) {
                    return;
                }

                $order = wc_get_order($order_id);
                if (! $order) {
                    return;
                }

                $user = wp_get_current_user();
                $isUserOnboarded = get_user_meta($user->ID, 'is_user_onboarded', true);
                $url = site_url('/sign-up/on-boarding');
                $confirmationAlertMsg = "";

                $parent_subscription_id = get_post_meta($order_id, '_subscription_renewal', true);

                if (!empty($parent_subscription_id)) {
                    $subscriptions = [wcs_get_subscription($parent_subscription_id)]; // Ensure compatibility with foreach
                } else {
                    $subscriptions = wcs_get_subscriptions_for_order($order_id);
                }

                if (! empty($subscriptions)) {
                    foreach ($subscriptions as $subscription) {
                        $delayed_payment = get_post_meta($subscription->get_id(), '_delayed_payment', true);
                        $order_delayed_payment = get_post_meta($order_id, '_delayed_payment', true);

                        if ($delayed_payment === 'true' || $order_delayed_payment === 'true') {
                            $orderItems = [];

                            foreach ($order->get_items() as $item) {
                                $orderItems[] = $item->get_name();
                            }

                            $productNames = implode(" | ", array_unique($orderItems));

                            if ($isUserOnboarded || current_user_can('administrator')) {
                                $url = get_permalink(wc_get_page_id('myaccount')) . "subscriptions";
                            } else {
                                do_action('emailReminderHook', $user->user_email, $url);
                            }
                            $subscription->update_status('active');
                            $subscription->save();
                            wp_safe_redirect($url);
                            exit;
                        }
                    }
                }
            }
        }
    }



    public function delayed_payment_popup()
    {
        if (is_user_logged_in() && strpos($_SERVER['REQUEST_URI'], '/sign-up/on-boarding') !== false || strpos($_SERVER['REQUEST_URI'], '/subscriptions') !== false) {
            if (get_option('wc_delay_subscription_enabled') !== 'yes') {
                return;
            }

            $user_id = get_current_user_id();
            $subscriptions = wcs_get_users_subscriptions($user_id);

            if (empty($subscriptions)) {
                return;
            }

            $show_popup = false;

            foreach ($subscriptions as $subscription) {
                if (!$subscription instanceof WC_Subscription) {
                    continue;
                }

                $status = $subscription->get_status();
                $delayed_payment = get_post_meta($subscription->get_id(), '_delayed_payment', true);

                // Check if the subscription contains an order item from the "plan" category
                foreach ($subscription->get_items() as $item) {
                    $product_id = $item->get_product_id();
                    if (has_term('plan', 'product_cat', $product_id)) {
                        if ($delayed_payment === 'true') {
                            $show_popup = true;
                            break 2; // Break both loops if conditions are met
                        }
                    }
                }
            }

            if (!$show_popup) {
                return;
            }

            $current_day = gmdate('w'); // 0 = Sunday, 6 = Saturday
            if ($show_popup && $current_day == 5 || $current_day == 6) {
?>
                <script>
                    document.addEventListener("DOMContentLoaded", function() {

                        function showPopupWhenReady() {

                            if (typeof elementorProFrontend !== "undefined" && elementorProFrontend.modules.popup && typeof elementorProFrontend.modules.popup.showPopup === "function") {
                                console.log("Elementor Pro Loaded, Showing Popup...");
                                elementorProFrontend.modules.popup.showPopup({
                                    id: 2350
                                });
                            } else {
                                console.log("Elementor Pro Not Ready, Retrying...");
                                setTimeout(showPopupWhenReady, 300);
                            }
                        }

                        // Run when the window is fully loaded to avoid issues
                        window.addEventListener("load", function() {
                            console.log("Window fully loaded, attempting to show popup...");
                            showPopupWhenReady();
                        });
                    });
                </script>

<?php
            }
        }
    }

    public function send_email_delayed_payment($subscription, $order_id, $chargeDate)
    {
        foreach ($subscription->get_items() as $subItem) {
            $order = wc_get_order($order_id);
            $currency = $order->get_currency();
            $amount = $order->get_total(); // Get charged amount

            $userEmail = $subscription->get_billing_email();
            $firstName = $subscription->get_billing_first_name();
            $lastName = $subscription->get_billing_last_name();
            $userName = trim("$firstName $lastName");
            $productName = esc_html($subItem['name']);

            $formattedChargeDate = date_i18n('F j, Y', strtotime($chargeDate));

            // Get the subscription amount
            $amount = $subscription->get_total();

            // Fallback for name
            if (empty($userName)) {
                $userName = 'there';
            }

            $subject = "Upcoming Payment Notice for Your Subscription";

            $message = "
            <p style='font-family: Helvetica, Arial, sans-serif; font-size: 13px; line-height: 1.5em;'>Hi $userName,</p>

            <p style='font-family: Helvetica, Arial, sans-serif; font-size: 13px; line-height: 1.5em;'>Just a quick reminder that we'll process your payment of <strong>$currency $amount</strong> for <strong>$productName</strong> on <strong>$formattedChargeDate</strong>.</p>

            <p style='font-family: Helvetica, Arial, sans-serif; font-size: 13px; line-height: 1.5em;'>If you have any questions, feel free to reach out to <a href='mailto:help@deerdesigner.com'>help@deerdesigner.com</a>.</p>

            <p style='font-family: Helvetica, Arial, sans-serif; font-size: 13px; line-height: 1.5em;'>Best regards,<br>
            The Deer Designer Team</p>
            ";

            $headers = ['Content-Type: text/html; charset=UTF-8'];

            wp_mail($userEmail, $subject, emailTemplate($message), $headers);
        }
    }


    public function send_email_successful_payment($subscription, $chargeDate, $order_id)
    {
        foreach ($subscription->get_items() as $subItem) {
            $order = wc_get_order($order_id);
            $currency = $order->get_currency();

            $userEmail = $subscription->get_billing_email();
            $firstName = $subscription->get_billing_first_name();
            $lastName = $subscription->get_billing_last_name();
            $userName = trim("$firstName $lastName");
            $productName = esc_html($subItem['name']); // Make sure this is correct

            $formattedChargeDate = date_i18n('F j, Y', strtotime($chargeDate));

            // Fallback for name
            if (empty($userName)) {
                $userName = 'there';
            }

            $amount = $order->get_total(); // Fetching the order total
            $subject = "Payment Successful - $productName";

            $message = "
            <p style='font-family: Helvetica, Arial, sans-serif; font-size: 13px; line-height: 1.5em;'>Hi $userName,</p>

            <p style='font-family: Helvetica, Arial, sans-serif; font-size: 13px; line-height: 1.5em;'>We've successfully processed your payment of <strong>$currency $amount</strong> for the <strong>$productName</strong> on <strong>$formattedChargeDate</strong> (Ref. number: <strong>$order_id</strong>)</p>

            <p style='font-family: Helvetica, Arial, sans-serif; font-size: 13px; line-height: 1.5em;'>If you have any questions, feel free to reach out to <a href='mailto:help@deerdesigner.com'>help@deerdesigner.com</a>.</p>

            <p style='font-family: Helvetica, Arial, sans-serif; font-size: 13px; line-height: 1.5em;'>Best regards,<br>
            The Deer Designer Team</p>
            ";

            // Ensure headers are properly set
            $headers = ['Content-Type: text/html; charset=UTF-8'];

            // Send email
            wp_mail($userEmail, $subject, $message, $headers);
        }
    }

    public function slackNotificationsDelayed($slackMessageBody, $slackWebHook = SLACK_WEBHOOK_URL){
        wp_remote_post($slackWebHook, array(
            'body'        => wp_json_encode($slackMessageBody),
            'headers' => array(
                'Content-type: application/json'
            ),
        ));
    }
}

new WC_Subscription_Payment_Delay();
