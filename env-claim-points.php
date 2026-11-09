<?php
/**
 * Plugin Name: [ENV] Receipt Claim Manager
 * Plugin URI: https://github.com/strsar/env-claim-points
 * Description: Adds a shortcode-based receipt claim form that looks up GoTab receipt details and submits (tabRef → tabId) eligible loyalty point claims through Clutch (barcodeClaim).
 * Version: 1.0.2
 * Author: Envisionit, Scott Trsar
 * Author URI: https://envisionitagency.com/
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Update URI: https://github.com/strsar/env-claim-points
 * Primary Branch: main
 * Developer: Scott Trsar
 * Contact: https://www.linkedin.com/in/trsar
 * Github: https://github.com/strsar
 */

if (!defined('ABSPATH')) {
  exit;
}

final class Receipt_Claim {
  const VERSION = '1.0.2';
  const OPTION = 'receipt_claim_options';
  const LOG_TABLE = 'receipt_claim_logs';
  const GITHUB_REPO = 'https://github.com/strsar/env-claim-points/';
  const GITHUB_TOKEN = '';

  private static $instance = null;
  private $update_checker = null;

  public static function instance() {
    if (self::$instance === null) {
      self::$instance = new self();
    }

    return self::$instance;
  }

  private function __construct() {
    $this->register_update_checker();
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    add_shortcode('receipt_claim_form', [$this, 'shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    add_action('rest_api_init', [$this, 'register_rest_routes']);
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);
    register_activation_hook(__FILE__, [__CLASS__, 'activate']);
  }

  /**
   * Checks GitHub releases for new versions so sites can update from the
   * WordPress Plugins screen. For a private repo, set GITHUB_TOKEN or use the
   * env_claim_points_github_token filter.
   */
  private function register_update_checker() {
    if ($this->update_checker !== null) {
      return;
    }

    $loader = plugin_dir_path(__FILE__) . 'lib/plugin-update-checker/plugin-update-checker.php';
    if (!file_exists($loader)) {
      return;
    }

    require_once $loader;

    $this->update_checker = \YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
      self::GITHUB_REPO,
      __FILE__,
      'env-claim-points'
    );

    $this->update_checker->setBranch('main');

    $token = self::GITHUB_TOKEN;
    if ($token === '') {
      $token = apply_filters('env_claim_points_github_token', '');
    }

    if (is_string($token) && trim($token) !== '') {
      $this->update_checker->setAuthentication(trim($token));
    }
  }

  public static function activate() {
    self::ensure_log_table();

    $defaults = self::defaults();
    $existing = get_option(self::OPTION, []);
    update_option(self::OPTION, wp_parse_args($existing, $defaults));
  }

  public static function defaults() {
    return [
      'clutch_environment' => 'stage',
      'clutch_stage_base_url' => 'https://api-stage.clutch.com',
      'clutch_prod_base_url' => 'https://api.clutch.com',
      'clutch_stage_api_key' => '',
      'clutch_stage_api_secret' => '',
      'clutch_stage_brand' => 'TheF8355',
      'clutch_stage_location' => '',
      'clutch_stage_terminal' => '',
      'clutch_stage_card_set_ids' => '',
      'clutch_prod_api_key' => '',
      'clutch_prod_api_secret' => '',
      'clutch_prod_brand' => 'TheF8355',
      'clutch_prod_location' => '',
      'clutch_prod_terminal' => '',
      'clutch_prod_card_set_ids' => '',
      'gotab_stage_token_url' => 'https://gotab.io/api/oauth/token',
      'gotab_stage_graph_url' => 'https://gotab.io/api/graph',
      'gotab_stage_api_access_id' => '',
      'gotab_stage_api_access_secret' => '',
      'gotab_stage_location_id' => '',
      'gotab_prod_token_url' => 'https://gotab.io/api/oauth/token',
      'gotab_prod_graph_url' => 'https://gotab.io/api/graph',
      'gotab_prod_api_access_id' => '',
      'gotab_prod_api_access_secret' => '',
      'gotab_prod_location_id' => '',
      'gotab_date_field' => 'created',
      'claim_duration_amount' => '1',
      'claim_duration_unit' => 'months',
      'rate_limit_attempts' => '15',
      'rate_limit_minutes' => '30',
      'success_message' => 'Your receipt was successfully claimed and points have been added to your account.',
      'already_claimed_message' => 'This receipt has already been applied to a loyalty account.',
      'generic_error_message' => 'We were unable to process this receipt claim. Please verify your information or try again later.',
      'gotab_not_found_message' => 'We couldn’t find a receipt matching this code. Please check the code printed on your receipt and try again.',
      'expired_message' => 'We\'re sorry, but this receipt is outside the eligible claim window.',
      'member_not_found_message' => 'We couldn’t find a loyalty account matching this information. Please check your details or try a different identifier.',
      'enable_honeypot' => '1',
      'debug_mode' => '0'
    ];
  }

  public function get_options() {
    return wp_parse_args(get_option(self::OPTION, []), self::defaults());
  }

  public function admin_menu() {
    add_options_page('Receipt Claim', 'Loyalty Point Claims', 'manage_options', 'receipt-claim', [$this, 'settings_page']);
  }

  public function plugin_action_links($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=receipt-claim')) . '">Settings</a>');
    return $links;
  }

  public function register_settings() {
    register_setting('receipt_claim_settings', self::OPTION, [$this, 'sanitize_options']);
  }

  public function sanitize_options($input) {
    $defaults = self::defaults();
    $existing = $this->get_options();
    $output = [];

    foreach ($defaults as $key => $default) {
      $value = isset($input[$key]) ? $input[$key] : $default;

      if (strpos($key, 'secret') !== false || strpos($key, 'key') !== false || strpos($key, 'access') !== false) {
        $output[$key] = sanitize_text_field($value);
      } elseif (strpos($key, 'url') !== false) {
        $output[$key] = esc_url_raw($value);
      } elseif (strpos($key, 'message') !== false) {
        $output[$key] = sanitize_textarea_field($value);
      } else {
        $output[$key] = sanitize_text_field($value);
      }
    }

    $output['claim_duration_amount'] = max(0, absint($output['claim_duration_amount']));
    $output['rate_limit_attempts'] = max(1, absint($output['rate_limit_attempts']));
    $output['rate_limit_minutes'] = max(1, absint($output['rate_limit_minutes']));
    $output['clutch_environment'] = in_array($output['clutch_environment'], ['stage', 'production'], true) ? $output['clutch_environment'] : 'stage';
    $output['claim_duration_unit'] = in_array($output['claim_duration_unit'], ['days', 'weeks', 'months'], true) ? $output['claim_duration_unit'] : 'months';
    $output['gotab_date_field'] = in_array($output['gotab_date_field'], ['created', 'updated', 'fiscalDay'], true) ? $output['gotab_date_field'] : 'created';
    $output['enable_honeypot'] = !empty($output['enable_honeypot']) ? '1' : '0';
    $output['debug_mode'] = !empty($output['debug_mode']) ? '1' : '0';

    delete_transient('gotab_token');
    $this->clear_gotab_token_transients($existing);
    $this->clear_gotab_token_transients($output);
    $this->clear_gotab_location_transients($existing);
    $this->clear_gotab_location_transients($output);

    return $output;
  }

  public function settings_page() {
    if (!current_user_can('manage_options')) {
      return;
    }

    $o = $this->get_options();
    ?>
    <div class="wrap">
      <h1>Receipt Claim</h1>
      <h2 class="nav-tab-wrapper" data-receipt-claim-tabs>
        <button type="button" class="nav-tab nav-tab-active" data-tab-target="receipt-claim-tab-settings">Settings</button>
        <button type="button" class="nav-tab" data-tab-target="receipt-claim-tab-how-it-works">How It Works</button>
        <button type="button" class="nav-tab" data-tab-target="receipt-claim-tab-clutch-api">Clutch API</button>
        <button type="button" class="nav-tab" data-tab-target="receipt-claim-tab-gotab-api">GoTab API</button>
        <button type="button" class="nav-tab" data-tab-target="receipt-claim-tab-claim-rules">Claim Rules</button>
        <button type="button" class="nav-tab" data-tab-target="receipt-claim-tab-messages">Messages</button>
        <button type="button" class="nav-tab" data-tab-target="receipt-claim-tab-logs">Logs</button>
      </h2>

      <style>
        .receipt-claim-tab-panel { display:none; margin-top:20px; }
        .receipt-claim-tab-panel.is-active { display:block; }
        .receipt-claim-tab-panel .form-table { margin-top:0; }
      </style>

      <form method="post" action="options.php">
        <?php settings_fields('receipt_claim_settings'); ?>

        <div id="receipt-claim-tab-settings" class="receipt-claim-tab-panel is-active">
          <h2>Settings</h2>
          <p>Use shortcode <code>[receipt_claim_form]</code> to render the claim form.</p>
          <p>This screen stores the API credentials, receipt-claim rules, and customer-facing messages used by the plugin.</p>
          <table class="form-table" role="presentation">
            <?php $this->field_select('clutch_environment', 'Active Environment', ['stage' => 'Stage', 'production' => 'Production'], $o); ?>
            <?php $this->field_text('rate_limit_attempts', 'Rate Limit Attempts', $o); ?>
            <?php $this->field_text('rate_limit_minutes', 'Rate Limit Window Minutes', $o); ?>
            <?php $this->field_checkbox('enable_honeypot', 'Enable Honeypot Field', $o); ?>
            <?php $this->field_checkbox('debug_mode', 'Debug Mode', $o, 'Logs API context. Do not enable in production unless troubleshooting.'); ?>
          </table>
          <?php submit_button('Save Settings'); ?>
        </div>

        <div id="receipt-claim-tab-how-it-works" class="receipt-claim-tab-panel">
          <h2>How It Works</h2>
          <ol>
            <li>The shortcode renders a custom <code>novalidate</code> form and the front-end script validates the selected identifier before submit.</li>
            <li>The browser sends a JSON request with <code>tabRef</code>, one identifier type, and the REST nonce to the WordPress endpoint.</li>
            <li>The REST handler checks the nonce, honeypot field, and rate limit before any external API call is made.</li>
            <li>The plugin resolves the submitted identifier type as <code>cardNumber</code>, <code>mobilePhone</code>, or <code>email</code>. If the explicit radio value is missing, it infers the type from the submitted field names.</li>
            <li>The plugin authenticates to GoTab with the configured API access credentials and reuses a cached token until it expires.</li>
            <li>The plugin looks up the receipt in GoTab by <code>tabRef</code>. It first loads the accessible GoTab locations, then sends one multi-location GoTab query that checks each location for a matching tab.</li>
            <li>The plugin checks the receipt date against the configured claim window using the selected GoTab date field.</li>
            <li>If the guest entered a card number, that value is used directly. If the guest entered a phone number, Clutch search runs with <code>phone</code> OR <code>mobilePhone</code>. If the guest entered an email address, Clutch search runs by <code>email</code>.</li>
            <li>If Clutch returns exactly one loyalty card, the plugin calls <code>/flex/barcodeClaim</code> with GoTab <code>tabId</code> as <code>externalId</code> and the resolved Clutch card number.</li>
            <li>The plugin treats the claim as successful only when Clutch returns both <code>success</code> and <code>checkoutWasClaimed</code> as truthy values.</li>
            <li>When Debug Mode is enabled, the plugin stores the upstream GoTab or Clutch response body in the log record and shows it in the Logs tab.</li>
          </ol>
        </div>

        <div id="receipt-claim-tab-clutch-api" class="receipt-claim-tab-panel">
          <h2>Clutch API</h2>
          <table class="form-table" role="presentation">
            <?php $this->field_text('clutch_stage_base_url', 'Stage Base URL', $o); ?>
            <?php $this->field_text('clutch_prod_base_url', 'Production Base URL', $o); ?>
            <?php $this->field_section('Stage Credentials', 'Used when Active Environment is Stage.'); ?>
            <?php $this->field_text('clutch_stage_api_key', 'Stage API Key', $o); ?>
            <?php $this->field_password('clutch_stage_api_secret', 'Stage API Secret', $o); ?>
            <?php $this->field_text('clutch_stage_brand', 'Stage Brand Header', $o); ?>
            <?php $this->field_text('clutch_stage_location', 'Stage Location Header', $o, 'If left blank, the plugin will pass the locationId returned by the GoTab tabRef lookup.'); ?>
            <?php $this->field_text('clutch_stage_terminal', 'Stage Terminal Header', $o); ?>
            <?php $this->field_text('clutch_stage_card_set_ids', 'Stage Card Set ID(s)', $o, 'Stored for Clutch card-set scoping if support confirms they are needed.'); ?>
            <?php $this->field_section('Production Credentials', 'Used when Active Environment is Production.'); ?>
            <?php $this->field_text('clutch_prod_api_key', 'Production API Key', $o); ?>
            <?php $this->field_password('clutch_prod_api_secret', 'Production API Secret', $o); ?>
            <?php $this->field_text('clutch_prod_brand', 'Production Brand Header', $o); ?>
            <?php $this->field_text('clutch_prod_location', 'Production Location Header', $o, 'If left blank, the plugin will pass the locationId returned by the GoTab tabRef lookup.'); ?>
            <?php $this->field_text('clutch_prod_terminal', 'Production Terminal Header', $o); ?>
            <?php $this->field_text('clutch_prod_card_set_ids', 'Production Card Set ID(s)', $o, 'Stored for Clutch card-set scoping if support confirms they are needed.'); ?>
          </table>
          <?php submit_button('Save Clutch API Settings'); ?>
        </div>

        <div id="receipt-claim-tab-gotab-api" class="receipt-claim-tab-panel">
          <h2>GoTab API</h2>
          <table class="form-table" role="presentation">
            <?php $this->field_section('Stage Credentials', 'Used when Active Environment is Stage.'); ?>
            <?php $this->field_text('gotab_stage_token_url', 'Stage Token URL', $o); ?>
            <?php $this->field_text('gotab_stage_graph_url', 'Stage GraphQL URL', $o); ?>
            <?php $this->field_text('gotab_stage_api_access_id', 'Stage API Access ID', $o); ?>
            <?php $this->field_password('gotab_stage_api_access_secret', 'Stage API Access Secret', $o); ?>
            <?php $this->field_text('gotab_stage_location_id', 'Stage GoTab Location ID', $o, 'Optional reference location to store with the environment. The receipt lookup now queries all accessible GoTab locations in one request.'); ?>
            <?php $this->field_section('Production Credentials', 'Used when Active Environment is Production.'); ?>
            <?php $this->field_text('gotab_prod_token_url', 'Production Token URL', $o); ?>
            <?php $this->field_text('gotab_prod_graph_url', 'Production GraphQL URL', $o); ?>
            <?php $this->field_text('gotab_prod_api_access_id', 'Production API Access ID', $o); ?>
            <?php $this->field_password('gotab_prod_api_access_secret', 'Production API Access Secret', $o); ?>
            <?php $this->field_text('gotab_prod_location_id', 'Production GoTab Location ID', $o, 'Optional reference location to store with the environment. The receipt lookup now queries all accessible GoTab locations in one request.'); ?>
            <?php $this->field_select('gotab_date_field', 'Receipt Date Field', ['created' => 'created', 'updated' => 'updated', 'fiscalDay' => 'fiscalDay'], $o); ?>
          </table>
          <?php submit_button('Save GoTab API Settings'); ?>
        </div>

        <div id="receipt-claim-tab-claim-rules" class="receipt-claim-tab-panel">
          <h2>Claim Rules</h2>
          <table class="form-table" role="presentation">
            <?php $this->field_text('claim_duration_amount', 'Claim Duration Amount', $o); ?>
            <?php $this->field_select('claim_duration_unit', 'Claim Duration Unit', ['days' => 'Days', 'weeks' => 'Weeks', 'months' => 'Months'], $o); ?>
          </table>
          <?php submit_button('Save Claim Rules'); ?>
        </div>

        <div id="receipt-claim-tab-messages" class="receipt-claim-tab-panel">
          <h2>Messages</h2>
          <table class="form-table" role="presentation">
            <?php $this->field_textarea('success_message', 'Success Message', $o); ?>
            <?php $this->field_textarea('already_claimed_message', 'Already Claimed Message', $o); ?>
            <?php $this->field_textarea('generic_error_message', 'Generic Error Message', $o); ?>
            <?php $this->field_textarea('gotab_not_found_message', 'GoTab Not Found Message', $o); ?>
            <?php $this->field_textarea('expired_message', 'Expired Receipt Message', $o); ?>
            <?php $this->field_textarea('member_not_found_message', 'Member Not Found Message', $o); ?>
          </table>
          <?php submit_button('Save Messages'); ?>
        </div>

        <div id="receipt-claim-tab-logs" class="receipt-claim-tab-panel">
          <h2>Logs</h2>
          <?php $this->render_logs(); ?>
        </div>
      </form>
    </div>
    <script>
      (function() {
        var tabLinks = document.querySelectorAll('[data-receipt-claim-tabs] [data-tab-target]');
        var panels = document.querySelectorAll('.receipt-claim-tab-panel');
        var storageKey = 'receipt-claim-active-tab';

        function getStoredTab() {
          try {
            return window.localStorage.getItem(storageKey);
          } catch(error) {
            return '';
          }
        }

        function setStoredTab(targetId) {
          try {
            window.localStorage.setItem(storageKey, targetId);
          } catch(error) {}
        }

        function activateTab(targetId) {
          var matched = false;

          panels.forEach(function(panel) {
            var isActive = panel.id === targetId;
            panel.classList.toggle('is-active', isActive);
            if(isActive) {
              matched = true;
            }
          });

          tabLinks.forEach(function(link) {
            var isActive = link.getAttribute('data-tab-target') === targetId;
            link.classList.toggle('nav-tab-active', isActive);
          });

          if(matched) {
            setStoredTab(targetId);
          }
        }

        tabLinks.forEach(function(link) {
          link.addEventListener('click', function(event) {
            event.preventDefault();
            var targetId = this.getAttribute('data-tab-target');
            activateTab(targetId);
          });
        });

        var initialTab = getStoredTab();
        activateTab(initialTab || 'receipt-claim-tab-settings');
      }());
    </script>
    <?php
  }

  private function field_name($key) {
    return self::OPTION . '[' . esc_attr($key) . ']';
  }

  private function field_text($key, $label, $o, $help = '') {
    ?>
    <tr><th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><input class="regular-text" type="text" id="<?php echo esc_attr($key); ?>" name="<?php echo $this->field_name($key); ?>" value="<?php echo esc_attr($o[$key]); ?>"><?php if ($help) : ?><p class="description"><?php echo esc_html($help); ?></p><?php endif; ?></td></tr>
    <?php
  }

  private function field_section($label, $help = '') {
    ?>
    <tr><th scope="row"><?php echo esc_html($label); ?></th><td><?php if ($help) : ?><p class="description"><?php echo esc_html($help); ?></p><?php endif; ?></td></tr>
    <?php
  }

  private function field_password($key, $label, $o) {
    ?>
    <tr><th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><input class="regular-text" type="password" id="<?php echo esc_attr($key); ?>" name="<?php echo $this->field_name($key); ?>" value="<?php echo esc_attr($o[$key]); ?>" autocomplete="new-password"></td></tr>
    <?php
  }

  private function field_textarea($key, $label, $o) {
    ?>
    <tr><th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><textarea class="large-text" rows="2" id="<?php echo esc_attr($key); ?>" name="<?php echo $this->field_name($key); ?>"><?php echo esc_textarea($o[$key]); ?></textarea></td></tr>
    <?php
  }

  private function field_select($key, $label, $choices, $o) {
    ?>
    <tr><th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><select id="<?php echo esc_attr($key); ?>" name="<?php echo $this->field_name($key); ?>"><?php foreach ($choices as $value => $text) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($o[$key], $value); ?>><?php echo esc_html($text); ?></option><?php endforeach; ?></select></td></tr>
    <?php
  }

  private function field_checkbox($key, $label, $o, $help = '') {
    ?>
    <tr><th scope="row"><?php echo esc_html($label); ?></th><td><label><input type="checkbox" name="<?php echo $this->field_name($key); ?>" value="1" <?php checked($o[$key], '1'); ?>> Enabled</label><?php if ($help) : ?><p class="description"><?php echo esc_html($help); ?></p><?php endif; ?></td></tr>
    <?php
  }

  private function render_logs() {
    global $wpdb;
    self::ensure_log_table();
    $table = $wpdb->prefix . self::LOG_TABLE;
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 20");

    if (!$rows) {
      echo '<p>No logs yet.</p>';
      return;
    }

    echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Status</th><th>Tab Ref</th><th>Loyalty Type</th><th>GoTab Tab ID</th><th>Clutch Request Ref</th><th>Message</th><th>Server Response</th></tr></thead><tbody>';

    foreach ($rows as $row) {
      $context = $this->format_log_context($row->raw_context);
      echo '<tr><td>' . esc_html($row->created_at) . '</td><td>' . esc_html($row->status) . '</td><td>' . esc_html($row->tab_ref) . '</td><td>' . esc_html($row->loyalty_type) . '</td><td>' . esc_html($row->gotab_tab_id) . '</td><td>' . esc_html($row->clutch_request_ref) . '</td><td>' . esc_html($row->message) . '</td><td><pre style="white-space:pre-wrap;word-break:break-word;margin:0;">' . esc_html($context) . '</pre></td></tr>';
    }

    echo '</tbody></table>';
  }

  private function format_log_context($context) {
    if (empty($context)) {
      return 'Debug mode off or no server response captured.';
    }

    $decoded = json_decode($context, true);

    if (json_last_error() === JSON_ERROR_NONE) {
      $pretty = wp_json_encode($decoded, JSON_PRETTY_PRINT);
      if (is_string($pretty) && $pretty !== '') {
        return $pretty;
      }
    }

    return (string) $context;
  }

  public function register_assets() {
    $style_path = plugin_dir_path(__FILE__) . 'assets/receipt-claim.css';
    $script_path = plugin_dir_path(__FILE__) . 'assets/receipt-claim.js';
    $style_version = file_exists($style_path) ? filemtime($style_path) : self::VERSION;
    $script_version = file_exists($script_path) ? filemtime($script_path) : self::VERSION;

    wp_register_style('receipt-claim', plugin_dir_url(__FILE__) . 'assets/receipt-claim.css', [], $style_version);
    wp_register_script('receipt-claim', plugin_dir_url(__FILE__) . 'assets/receipt-claim.js', [], $script_version, true);
  }

  public function shortcode($atts = []) {
    wp_enqueue_style('receipt-claim');
    wp_enqueue_script('receipt-claim');

    wp_localize_script('receipt-claim', 'ReceiptClaim', [
      'endpoint' => esc_url_raw(rest_url('/v1/receipt-claim')),
      'nonce' => wp_create_nonce('wp_rest')
    ]);

    $atts = shortcode_atts([
      'title' => 'Claim Missing Loyalty Points',
      'desc'  => 'Forgot to attach your loyalty account during your last visit? Enter your receipt details below to retroactively claim your points.',
      'button' => 'Claim Points'
    ], $atts, 'receipt_claim_form');

    ob_start();
    ?>
    
    <form id="claim-points" class="receipt-claim" data-receipt-claim novalidate>
      <div class="receipt-claim__intro">
        <h3><?php echo $atts['title']; ?></h3>
        <?php if($atts['desc']) : ?>
          <p><?php echo $atts['desc']; ?></p>
        <?php endif; ?>
      </div>
      
      <div class="receipt-claim__field"><label for="tab-ref">Receipt Code</label><input type="text" id="tab-ref" name="tabRef" required autocomplete="off" aria-describedby="tab-ref-help"><p class="receipt-claim__help" id="tab-ref-help">Enter the code printed next to &ldquo;Code&rdquo; on your receipt.</p></div>
      <fieldset class="receipt-claim__fieldset">
        <legend>Choose one loyalty identifier</legend>
        <label class="receipt-claim__choice"><input type="radio" name="loyaltyType" value="cardNumber" checked><span>Loyalty card number</span></label>
        <div class="receipt-claim__field" data-loyalty-field="cardNumber"><input type="text" name="cardNumber" inputmode="numeric" autocomplete="off"></div>
        <label class="receipt-claim__choice"><input type="radio" name="loyaltyType" value="mobilePhone"><span>Phone number</span></label>
        <div class="receipt-claim__field" data-loyalty-field="mobilePhone" hidden><input type="tel" name="mobilePhone" autocomplete="tel"></div>
        <label class="receipt-claim__choice"><input type="radio" name="loyaltyType" value="email"><span>Email address</span></label>
        <div class="receipt-claim__field" data-loyalty-field="email" hidden><input type="email" name="email" autocomplete="email"></div>
      </fieldset>
      <div class="receipt-claim__hp" aria-hidden="true"><label>Leave this field blank <input type="text" name="companyWebsite" tabindex="-1" autocomplete="off"></label></div>
      <button type="submit"><?php echo esc_html($atts['button']); ?></button>
      <div class="receipt-claim__message" data-message aria-live="polite"></div>
    </form>

    <?php
    return ob_get_clean();
  }

  public function register_rest_routes() {
    register_rest_route('/v1', '/receipt-claim', [
      'methods' => 'POST',
      'callback' => [$this, 'handle_claim'],
      'permission_callback' => '__return_true'
    ]);
  }

  public function handle_claim(WP_REST_Request $request) {
    $o = $this->active_api_options($this->get_options());
    $params = $request->get_json_params();

    if (!wp_verify_nonce($request->get_header('x_wp_nonce'), 'wp_rest')) {
      return $this->error_response('invalid_nonce', 'Invalid request.', 403);
    }

    if ($o['enable_honeypot'] === '1' && !empty($params['companyWebsite'])) {
      return $this->error_response('spam', $o['generic_error_message'], 400);
    }

    if (!$this->passes_rate_limit($o)) {
      return $this->error_response('rate_limited', $this->rate_limit_message($o), 429);
    }

    $tab_ref = isset($params['tabRef']) ? sanitize_text_field($params['tabRef']) : '';
    $loyalty_type = isset($params['loyaltyType']) ? $this->normalize_loyalty_type($params['loyaltyType']) : '';
    $card_number = '';

    if ($loyalty_type === '') {
      $has_card_key = array_key_exists('cardNumber', $params);
      $has_mobile_key = array_key_exists('mobilePhone', $params);
      $has_email_key = array_key_exists('email', $params);
      $has_card_number = !empty(preg_replace('/\D+/', '', (string) ($params['cardNumber'] ?? '')));
      $has_mobile_phone = !empty($this->normalize_phone($params['mobilePhone'] ?? ''));
      $has_email = !empty($params['email']) && is_email(sanitize_email($params['email']));

      if (($has_card_key || $has_card_number) && !$has_mobile_key && !$has_email_key) {
        $loyalty_type = 'cardNumber';
      } elseif (!$has_card_key && ($has_mobile_key || $has_mobile_phone) && !$has_email_key) {
        $loyalty_type = 'mobilePhone';
      } elseif (!$has_card_key && !$has_mobile_key && ($has_email_key || $has_email)) {
        $loyalty_type = 'email';
      } elseif ($has_card_number && !$has_mobile_phone && !$has_email) {
        $loyalty_type = 'cardNumber';
      } elseif (!$has_card_number && $has_mobile_phone && !$has_email) {
        $loyalty_type = 'mobilePhone';
      } elseif (!$has_card_number && !$has_mobile_phone && $has_email) {
        $loyalty_type = 'email';
      }
    }

    if ($tab_ref === '') {
      return $this->error_response('missing_tab_ref', 'Please enter the Tab Reference ID from your receipt.', 400);
    }

    if (!in_array($loyalty_type, ['cardNumber', 'mobilePhone', 'email'], true)) {
      return $this->error_response('invalid_loyalty_type', 'Please choose one loyalty identifier.', 400);
    }

    if ($loyalty_type === 'cardNumber') {
      $card_number = isset($params['cardNumber']) ? preg_replace('/\D+/', '', (string) $params['cardNumber']) : '';
      if ($card_number === '') {
        return $this->error_response('missing_card', 'Please enter your loyalty card number.', 400);
      }
    }

    $tab = $this->gotab_lookup_tab($tab_ref, $o);

    if (is_wp_error($tab)) {
      $this->log_attempt(['tab_ref' => $tab_ref, 'loyalty_type' => $loyalty_type, 'status' => 'gotab_error', 'message' => $tab->get_error_message(), 'raw_context' => $this->debug_context($tab->get_error_data(), $o)], $o);
      return $this->error_response('gotab_error', $tab->get_error_message(), 400);
    }

    if (!$this->tab_is_within_claim_window($tab, $o)) {
      $this->log_attempt(['tab_ref' => $tab_ref, 'loyalty_type' => $loyalty_type, 'status' => 'expired', 'message' => $o['expired_message'], 'gotab_tab_id' => $tab['tabId'] ?? '', 'raw_context' => $this->debug_context($tab, $o)], $o);
      return $this->error_response('expired', $o['expired_message'], 400);
    }

    if ($o['clutch_location'] === '' && !empty($tab['locationId'])) {
      $o['clutch_location'] = (string) $tab['locationId'];
    }

    if ($loyalty_type === 'mobilePhone') {
      $phone = isset($params['mobilePhone']) ? $this->normalize_phone($params['mobilePhone']) : '';
      if ($phone === '') {
        return $this->error_response('invalid_phone', 'Please enter a valid phone number.', 400);
      }
      $card_number = $this->clutch_search_card(['phone' => $phone, 'mobilePhone' => $phone], $o, false);
    }

    if ($loyalty_type === 'email') {
      $email = isset($params['email']) ? sanitize_email($params['email']) : '';
      if (!is_email($email)) {
        return $this->error_response('invalid_email', 'Please enter a valid email address.', 400);
      }
      $card_number = $this->clutch_search_card(['email' => $email], $o);
    }

    if (is_wp_error($card_number)) {
      $this->log_attempt(['tab_ref' => $tab_ref, 'loyalty_type' => $loyalty_type, 'status' => 'member_not_found', 'message' => $card_number->get_error_message(), 'gotab_tab_id' => $tab['tabId'] ?? '', 'raw_context' => $this->debug_context($card_number->get_error_data(), $o)], $o);
      return $this->error_response('member_not_found', $card_number->get_error_message(), 400);
    }

    $claim = $this->clutch_barcode_claim((string) $tab['tabId'], (string) $card_number, $o);

    if (is_wp_error($claim)) {
      $data = $claim->get_error_data();
      $this->log_attempt(['tab_ref' => $tab_ref, 'loyalty_type' => $loyalty_type, 'status' => 'claim_error', 'message' => $claim->get_error_message(), 'gotab_tab_id' => $tab['tabId'] ?? '', 'clutch_request_ref' => $data['requestRef'] ?? '', 'clutch_checkout_request_ref' => $data['checkoutRequestRef'] ?? '', 'clutch_success' => isset($data['success']) ? (int) (bool) $data['success'] : null, 'clutch_checkout_was_claimed' => isset($data['checkoutWasClaimed']) ? (int) (bool) $data['checkoutWasClaimed'] : null, 'raw_context' => $this->debug_context($data, $o)], $o);
      return $this->error_response('claim_error', $claim->get_error_message(), 400, ['requestRef' => $data['requestRef'] ?? '']);
    }

    $success = !empty($claim['success']);
    $checkout_was_claimed = !empty($claim['checkoutWasClaimed']);
    $request_ref = $claim['requestRef'] ?? '';
    $checkout_request_ref = $claim['checkoutRequestRef'] ?? '';

    if ($success && $checkout_was_claimed) {
      $this->log_attempt(['tab_ref' => $tab_ref, 'loyalty_type' => $loyalty_type, 'status' => 'success', 'message' => $o['success_message'], 'gotab_tab_id' => $tab['tabId'] ?? '', 'clutch_request_ref' => $request_ref, 'clutch_checkout_request_ref' => $checkout_request_ref, 'clutch_success' => 1, 'clutch_checkout_was_claimed' => 1, 'raw_context' => $this->debug_context($claim, $o)], $o);
      return rest_ensure_response(['success' => true, 'message' => $o['success_message'], 'requestRef' => $request_ref]);
    }

    $message = $success && !$checkout_was_claimed ? $o['already_claimed_message'] : $this->clutch_claim_message($claim, $o);
    $this->log_attempt(['tab_ref' => $tab_ref, 'loyalty_type' => $loyalty_type, 'status' => 'not_claimed', 'message' => $message, 'gotab_tab_id' => $tab['tabId'] ?? '', 'clutch_request_ref' => $request_ref, 'clutch_checkout_request_ref' => $checkout_request_ref, 'clutch_success' => (int) $success, 'clutch_checkout_was_claimed' => (int) $checkout_was_claimed, 'raw_context' => $this->debug_context($claim, $o)], $o);

    return $this->error_response('not_claimed', $message, 400, ['requestRef' => $request_ref]);
  }

  private function gotab_lookup_tab($tab_ref, $o) {
    $token = $this->gotab_token($o);
    if (is_wp_error($token)) {
      return $token;
    }

    $location_ids = $this->gotab_location_ids($token, $o);

    if (is_wp_error($location_ids)) {
      return $location_ids;
    }

    if (empty($location_ids)) {
      return new WP_Error('gotab_missing_locations', 'We couldn’t look up that receipt right now. Please try again.');
    }

    $fields = 'tabId tabUuid tabRef status total created updated fiscalDay locationId';
    $aliases = [];
    $query_parts = [];
    $matches = [];
    $alias_index = 0;
    $fiscal_day_filter = $this->gotab_fiscal_day_filter($o);

    foreach ($location_ids as $location_id) {
      $alias = 'loc' . $alias_index;
      $aliases[$alias] = (string) $location_id;
      $query_parts[] = $alias . ': tabsList(condition: { locationId: "' . $this->format_bigint_variable($location_id) . '" }, filter: { tabRef: { equalTo: $tabRef }' . $fiscal_day_filter . ' }, first: 2) { ' . $fields . ' }';
      $alias_index++;
    }

    $query = 'query FindTabByRefAcrossLocations($tabRef: String!) { ' . implode(' ', $query_parts) . ' }';
    $body = $this->gotab_graph_query($token, $o, $query, ['tabRef' => $tab_ref], true);

    if (is_wp_error($body)) {
      return $body;
    }

    $errors = !empty($body['errors']) && is_array($body['errors']) ? $body['errors'] : [];
    $error_aliases = [];

    foreach ($errors as $error) {
      $path = !empty($error['path']) && is_array($error['path']) ? $error['path'] : [];
      if (!empty($path[0]) && is_string($path[0])) {
        $error_aliases[] = $path[0];
      }
    }

    $error_aliases = array_values(array_unique($error_aliases));
    $lookup_context = ['aliases' => $aliases, 'response' => $body];

    foreach ($aliases as $alias => $location_id) {
      $tabs = $body['data'][$alias] ?? [];

      if ($tabs === null && in_array($alias, $error_aliases, true)) {
        continue;
      }

      if (!is_array($tabs)) {
        $tabs = [];
      }

      if (count($tabs) > 1) {
        return new WP_Error('gotab_multiple_matches', 'We found more than one matching receipt for that number. Please contact support.', $lookup_context);
      }

      if (count($tabs) === 1) {
        $matches[] = $tabs[0];

        if (count($matches) > 1) {
          return new WP_Error('gotab_multiple_matches', 'We found more than one matching receipt for that number. Please contact support.', ['matches' => $matches, 'aliases' => $aliases, 'response' => $body]);
        }
      }
    }

    if (!empty($errors) && count($matches) < 1) {
      return new WP_Error('gotab_graph_error', 'We couldn’t look up that receipt right now. Please try again.', $lookup_context);
    }

    if (count($matches) < 1) {
      return new WP_Error('gotab_not_found', $o['gotab_not_found_message'], $body);
    }

    if (empty($matches[0]['tabId'])) {
      return new WP_Error('gotab_missing_tab_id', 'We found a matching receipt, but we couldn’t verify it. Please try again or contact support.', $matches[0]);
    }

    return $matches[0];
  }

  private function gotab_token($o) {
    $cached = get_transient($this->gotab_token_transient_key($o));
    if (!empty($cached)) {
      return $cached;
    }

    if ($o['gotab_api_access_id'] === '' || $o['gotab_api_access_secret'] === '') {
      return new WP_Error('gotab_missing_credentials', 'The receipt lookup service is not available right now.');
    }

    $response = wp_remote_post($o['gotab_token_url'], ['timeout' => 20, 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'], 'body' => wp_json_encode(['api_access_id' => $o['gotab_api_access_id'], 'api_access_secret' => $o['gotab_api_access_secret']])]);

    if (is_wp_error($response)) {
      return new WP_Error('gotab_auth_failed', 'The receipt lookup service is not available right now.', $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300 || empty($body['token'])) {
      return new WP_Error('gotab_auth_failed', 'The receipt lookup service is not available right now.', $body ?: ['httpCode' => $code]);
    }

    $ttl = !empty($body['expiresIn']) ? max(60, absint($body['expiresIn']) - 120) : DAY_IN_SECONDS - 120;
    set_transient($this->gotab_token_transient_key($o), $body['token'], $ttl);

    return $body['token'];
  }

  private function gotab_location_ids($token, $o) {
    $cached = get_transient($this->gotab_location_transient_key($o));

    if (is_array($cached) && !empty($cached)) {
      return $cached;
    }

    $query = 'query ListLocations { locationsList { locationId } }';
    $body = $this->gotab_graph_query($token, $o, $query);

    if (is_wp_error($body)) {
      return $body;
    }

    $locations = $body['data']['locationsList'] ?? [];
    $location_ids = [];

    foreach ($locations as $location) {
      if (!empty($location['locationId'])) {
        $location_ids[] = (string) $location['locationId'];
      }
    }

    $location_ids = array_values(array_unique($location_ids));

    if ($o['gotab_location_id'] !== '' && in_array((string) $o['gotab_location_id'], $location_ids, true)) {
      $preferred = (string) $o['gotab_location_id'];
      $location_ids = array_values(array_diff($location_ids, [$preferred]));
      array_unshift($location_ids, $preferred);
    }

    if (!empty($location_ids)) {
      set_transient($this->gotab_location_transient_key($o), $location_ids, 30 * MINUTE_IN_SECONDS);
    }

    return $location_ids;
  }

  private function gotab_graph_query($token, $o, $query, $variables = [], $allow_partial_errors = false) {
    $response = wp_remote_post($o['gotab_graph_url'], ['timeout' => 20, 'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'], 'body' => wp_json_encode(['query' => $query, 'variables' => $variables])]);

    if (is_wp_error($response)) {
      return new WP_Error('gotab_request_failed', 'We couldn’t look up that receipt right now. Please try again.', $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300 || (!$allow_partial_errors && !empty($body['errors']))) {
      delete_transient('gotab_token');
      delete_transient($this->gotab_token_transient_key($o));
      return new WP_Error('gotab_graph_error', 'We couldn’t look up that receipt right now. Please try again.', $body ?: ['httpCode' => $code]);
    }

    return $body;
  }

  private function clutch_search_card($filters, $o, $filter_logic_and = true) {
    $url = trailingslashit($this->clutch_base_url($o)) . 'merchant/search';
    if (!is_array($filters)) {
      $filters = [];
    }

    $response = wp_remote_post($url, ['timeout' => 20, 'headers' => $this->clutch_headers($o), 'body' => wp_json_encode(['filters' => $filters, 'filterLogicAnd' => (bool) $filter_logic_and, 'returnFields' => ['customer' => true, 'balances' => true], 'limit' => 2])]);

    if (is_wp_error($response)) {
      return new WP_Error('clutch_search_failed', 'Loyalty lookup failed. Please try again.', $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300 || empty($body['success'])) {
      return new WP_Error('clutch_search_failed', 'Loyalty lookup failed. Please try again.', $body ?: ['httpCode' => $code]);
    }

    $cards = $body['cards'] ?? [];

    if (count($cards) < 1) {
      return new WP_Error('clutch_member_not_found', $o['member_not_found_message'], $body);
    }

    if (count($cards) > 1) {
      return new WP_Error('clutch_multiple_members', 'We found more than one loyalty account with that information. Please use your loyalty card number instead.', $body);
    }

    if (empty($cards[0]['cardNumber'])) {
      return new WP_Error('clutch_missing_card_number', 'We found your loyalty account, but the loyalty system did not return a card number for it. Please use a different identifier or contact support.', $body);
    }

    return (string) $cards[0]['cardNumber'];
  }

  private function clutch_barcode_claim($external_id, $card_number, $o) {
    $url = trailingslashit($this->clutch_base_url($o)) . 'flex/barcodeClaim';
    $response = wp_remote_post($url, ['timeout' => 25, 'headers' => $this->clutch_claim_headers($external_id, $o), 'body' => wp_json_encode(['externalId' => $external_id, 'cardNumber' => $card_number])]);

    if (is_wp_error($response)) {
      return new WP_Error('clutch_claim_failed', 'Receipt claim failed. Please try again.', $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300) {
      return new WP_Error('clutch_claim_failed', $this->clutch_claim_message($body, $o), $body ?: ['httpCode' => $code]);
    }

    if (!is_array($body)) {
      return new WP_Error('clutch_invalid_response', 'We couldn’t process this receipt claim right now. Please try again later.');
    }

    return $body;
  }

  private function clutch_claim_headers($external_id, $o) {
    $request_id = wp_generate_uuid4();

    return array_merge($this->clutch_headers($o), [
      'customRequestId' => $request_id,
      'externalTransactionId' => $this->clutch_external_transaction_id($external_id, $request_id)
    ]);
  }

  private function clutch_external_transaction_id($external_id, $request_id) {
    $root = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $external_id);
    $root = trim($root, '-_');
    $suffix = gmdate('YmdHis') . '-' . substr(str_replace('-', '', (string) $request_id), 0, 8);

    if ($root === '') {
      return 'claim-' . $suffix;
    }

    return $root . '-claim-' . $suffix;
  }

  private function clutch_headers($o) {
    return ['Authorization' => 'Basic ' . base64_encode($o['clutch_api_key'] . ':' . $o['clutch_api_secret']), 'brand' => $o['clutch_brand'], 'location' => $o['clutch_location'], 'terminal' => $o['clutch_terminal'], 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
  }

  private function clutch_base_url($o) {
    return $o['clutch_environment'] === 'production' ? untrailingslashit($o['clutch_prod_base_url']) : untrailingslashit($o['clutch_stage_base_url']);
  }

  private function active_api_options($options) {
    $options['clutch_api_key'] = $this->environment_option($options, 'clutch_stage_api_key', 'clutch_prod_api_key');
    $options['clutch_api_secret'] = $this->environment_option($options, 'clutch_stage_api_secret', 'clutch_prod_api_secret');
    $options['clutch_brand'] = $this->environment_option($options, 'clutch_stage_brand', 'clutch_prod_brand');
    $options['clutch_location'] = $this->environment_option($options, 'clutch_stage_location', 'clutch_prod_location');
    $options['clutch_terminal'] = $this->environment_option($options, 'clutch_stage_terminal', 'clutch_prod_terminal');
    $options['clutch_card_set_ids'] = $this->environment_option($options, 'clutch_stage_card_set_ids', 'clutch_prod_card_set_ids');
    $options['gotab_token_url'] = $this->environment_option($options, 'gotab_stage_token_url', 'gotab_prod_token_url');
    $options['gotab_graph_url'] = $this->environment_option($options, 'gotab_stage_graph_url', 'gotab_prod_graph_url');
    $options['gotab_api_access_id'] = $this->environment_option($options, 'gotab_stage_api_access_id', 'gotab_prod_api_access_id');
    $options['gotab_api_access_secret'] = $this->environment_option($options, 'gotab_stage_api_access_secret', 'gotab_prod_api_access_secret');
    $options['gotab_location_id'] = $this->environment_option($options, 'gotab_stage_location_id', 'gotab_prod_location_id');

    return $options;
  }

  private function environment_option($options, $stage_key, $prod_key) {
    $environment_key = $options['clutch_environment'] === 'production' ? $prod_key : $stage_key;

    return $options[$environment_key];
  }

  private function clear_gotab_token_transients($options) {
    delete_transient($this->gotab_token_transient_key($options, 'stage'));
    delete_transient($this->gotab_token_transient_key($options, 'production'));
  }

  private function clear_gotab_location_transients($options) {
    delete_transient($this->gotab_location_transient_key($options, 'stage'));
    delete_transient($this->gotab_location_transient_key($options, 'production'));
  }

  private function gotab_token_transient_key($options, $environment = '') {
    if ($environment === '') {
      $environment = $options['clutch_environment'] === 'production' ? 'production' : 'stage';
    }

    $token_url = $environment === 'production' ? $options['gotab_prod_token_url'] : $options['gotab_stage_token_url'];
    $access_id = $environment === 'production' ? $options['gotab_prod_api_access_id'] : $options['gotab_stage_api_access_id'];

    return 'receipt_claim_gotab_token_' . md5($environment . '|' . $token_url . '|' . $access_id);
  }

  private function gotab_location_transient_key($options, $environment = '') {
    if ($environment === '') {
      $environment = $options['clutch_environment'] === 'production' ? 'production' : 'stage';
    }

    $graph_url = $environment === 'production' ? $options['gotab_prod_graph_url'] : $options['gotab_stage_graph_url'];
    $access_id = $environment === 'production' ? $options['gotab_prod_api_access_id'] : $options['gotab_stage_api_access_id'];

    return 'receipt_claim_gotab_locations_' . md5($environment . '|' . $graph_url . '|' . $access_id);
  }

  /**
   * Limits the tab lookup to the claim window using GoTab's indexed fiscalDay
   * column. Without it, tabRef (not indexed) forces a full-history scan per
   * location and the query hits GoTab's 5-second timeout. One extra day on
   * each side covers timezone differences between WordPress and the location.
   */
  private function gotab_fiscal_day_filter($o) {
    $amount = absint($o['claim_duration_amount']);
    if ($amount === 0) {
      return '';
    }

    $now = current_time('timestamp');
    $from = gmdate('Y-m-d', strtotime('-' . $amount . ' ' . $o['claim_duration_unit'] . ' -1 day', $now));
    $to = gmdate('Y-m-d', strtotime('+1 day', $now));

    return ', fiscalDay: { greaterThanOrEqualTo: "' . $from . '", lessThanOrEqualTo: "' . $to . '" }';
  }

  private function tab_is_within_claim_window($tab, $o) {
    $amount = absint($o['claim_duration_amount']);
    if ($amount === 0) {
      return true;
    }

    $field = $o['gotab_date_field'];
    $value = $tab[$field] ?? '';
    if ($value === '') {
      return false;
    }

    $timestamp = strtotime($value);
    if (!$timestamp) {
      return false;
    }

    $cutoff = strtotime('-' . $amount . ' ' . $o['claim_duration_unit'], current_time('timestamp'));
    return $timestamp >= $cutoff;
  }

  private function normalize_phone($phone) {
    $phone = trim((string) $phone);
    if (preg_match('/^\+\d{8,15}$/', $phone)) {
      return $phone;
    }

    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 10) {
      return '+1' . $digits;
    }

    if (strlen($digits) >= 8 && strlen($digits) <= 15) {
      return '+' . $digits;
    }

    return '';
  }

  private function normalize_loyalty_type($value) {
    $value = sanitize_key($value);

    if ($value === 'cardnumber') {
      return 'cardNumber';
    }

    if ($value === 'mobilephone') {
      return 'mobilePhone';
    }

    if ($value === 'email') {
      return 'email';
    }

    return '';
  }

  private function clutch_claim_message($body, $o) {
    $message = $this->upstream_error_message($body);
    $message_lower = strtolower($message);

    if (strpos($message_lower, 'no checkout found with externalid') !== false) {
      return 'We found your loyalty account, but this receipt is not available to claim yet. Please verify the receipt code and try again later.';
    }

    if (strpos($message_lower, 'more than one transaction found with externalid') !== false) {
      return $o['already_claimed_message'];
    }

    if (strpos($message_lower, 'already claimed') !== false) {
      return $o['already_claimed_message'];
    }

    return $o['generic_error_message'];
  }

  private function upstream_error_message($body) {
    if (is_string($body)) {
      return sanitize_text_field($body);
    }

    if (!is_array($body)) {
      return '';
    }

    foreach (['errorMessage', 'message', 'error', 'error_description'] as $key) {
      if (!empty($body[$key]) && is_scalar($body[$key])) {
        return sanitize_text_field((string) $body[$key]);
      }
    }

    if (!empty($body['errors']) && is_array($body['errors'])) {
      foreach ($body['errors'] as $error) {
        if (is_array($error)) {
          foreach (['message', 'errorMessage'] as $key) {
            if (!empty($error[$key]) && is_scalar($error[$key])) {
              return sanitize_text_field((string) $error[$key]);
            }
          }
        } elseif (is_string($error) && $error !== '') {
          return sanitize_text_field($error);
        }
      }
    }

    return '';
  }

  private function format_bigint_variable($value) {
    $digits = preg_replace('/\D+/', '', (string) $value);
    return $digits === '' ? $value : $digits;
  }

  private function passes_rate_limit($o) {
    $ip_hash = $this->ip_hash();
    $key = 'receipt_claim_rate_' . $ip_hash;
    $attempts = get_transient($key);
    $attempts = $attempts ? absint($attempts) : 0;

    if ($attempts >= absint($o['rate_limit_attempts'])) {
      return false;
    }

    set_transient($key, $attempts + 1, absint($o['rate_limit_minutes']) * MINUTE_IN_SECONDS);
    return true;
  }

  private function rate_limit_message($o) {
    $attempts = max(1, absint($o['rate_limit_attempts']));
    $minutes = max(1, absint($o['rate_limit_minutes']));
    $attempt_label = $attempts === 1 ? 'attempt' : 'attempts';
    $minute_label = $minutes === 1 ? 'minute' : 'minutes';

    return sprintf('Too many attempts. Limit is %d %s per %d %s. Please wait and try again.', $attempts, $attempt_label, $minutes, $minute_label);
  }

  private function ip_hash() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash_hmac('sha256', $ip, wp_salt('auth'));
  }

  private function log_attempt($data, $o = []) {
    if ($this->logging_disabled($o)) {
      return;
    }

    global $wpdb;
    self::ensure_log_table();
    $table = $wpdb->prefix . self::LOG_TABLE;
    $defaults = ['created_at' => current_time('mysql'), 'ip_hash' => $this->ip_hash(), 'tab_ref' => '', 'loyalty_type' => '', 'status' => '', 'message' => '', 'gotab_tab_id' => '', 'clutch_request_ref' => '', 'clutch_checkout_request_ref' => '', 'clutch_success' => null, 'clutch_checkout_was_claimed' => null, 'raw_context' => null];
    $wpdb->insert($table, wp_parse_args($data, $defaults));
  }

  private function logging_disabled($o) {
    return empty($o['debug_mode']) || $o['debug_mode'] !== '1';
  }

  private static function ensure_log_table() {
    global $wpdb;

    $table = $wpdb->prefix . self::LOG_TABLE;

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
      return;
    }

    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME NOT NULL,
      ip_hash VARCHAR(64) NULL,
      tab_ref VARCHAR(128) NULL,
      loyalty_type VARCHAR(32) NULL,
      status VARCHAR(32) NULL,
      message TEXT NULL,
      gotab_tab_id VARCHAR(128) NULL,
      clutch_request_ref VARCHAR(128) NULL,
      clutch_checkout_request_ref VARCHAR(128) NULL,
      clutch_success TINYINT(1) NULL,
      clutch_checkout_was_claimed TINYINT(1) NULL,
      raw_context LONGTEXT NULL,
      PRIMARY KEY (id),
      KEY created_at (created_at),
      KEY status (status),
      KEY ip_hash (ip_hash)
    ) {$charset};");
  }

  private function debug_context($data, $o) {
    if ($this->logging_disabled($o)) {
      return null;
    }

    return wp_json_encode($data);
  }

  private function error_response($code, $message, $status = 400, $extra = []) {
    return new WP_REST_Response(array_merge(['success' => false, 'code' => $code, 'message' => $message], $extra), $status);
  }
}

Receipt_Claim::instance();
