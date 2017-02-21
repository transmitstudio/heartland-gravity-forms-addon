<?php
GFForms::include_payment_addon_framework();
include_once 'class-gf-field-hpsach.php';
include_once 'class-gf-field-hpssecurecc.php';

/**
 * Handles Heartlands Payments with Gravity Forms
 * Class GFSecureSubmit
 */
class GFSecureSubmit
    extends GFPaymentAddOn {
    private $processPaymentsFor = array( 'creditcard','hpscreditcard','hpsACH' );
    private $ccFields = array( 'creditcard','hpscreditcard' );
    /**
     * @var bool
     */
    private $isCC = false;
    /**
     * @var bool|\GF_Field_HPSach
     */
    private $isACH = false;
    /**
     * @var string
     */
    protected $_version = GF_SECURESUBMIT_VERSION;
    /**
     * @var string
     */
    protected $_min_gravityforms_version = '1.9.1.1';
    /**
     * @var string
     */
    protected $_slug = 'gravityforms-securesubmit';
    /**
     * @var string
     */
    protected $_path = 'gravityforms-securesubmit/gravityforms-securesubmit.php';
    /**
     * @var string
     */
    protected $_full_path = __FILE__;
    /**
     * @var string
     */
    protected $_title = 'Gravity Forms SecureSubmit Add-On';
    /**
     * @var string
     */
    protected $_short_title = 'SecureSubmit';
    /**
     * @var bool
     */
    protected $_requires_credit_card = true;
    /**
     * @var bool
     */
    protected $_supports_callbacks = true;
    /**
     * @var bool
     */
    protected $_enable_rg_autoupgrade = true;
    // Permissions
    /**
     * @var string
     */
    protected $_capabilities_settings_page = 'gravityforms_securesubmit';
    /**
     * @var string
     */
    protected $_capabilities_form_settings = 'gravityforms_securesubmit';
    /**
     * @var string
     */
    protected $_capabilities_uninstall = 'gravityforms_securesubmit_uninstall';
    //Members plugin integration
    /**
     * @var array
     */
    protected $_capabilities
        = array(
            'gravityforms_securesubmit',
            'gravityforms_securesubmit_uninstall'
        );
    /**
     * @var null
     */
    private static $_instance = null;
    /**
     * @var null
     */
    public $transaction_response = null;
    /**
     * @return GFSecureSubmit|null
     */
    public static function get_instance() {
        if (self::$_instance == null) {
            self::$_instance = new GFSecureSubmit();
        }

        return self::$_instance;
    }
    /** Add our Secure ACH button to the pricing fields.
     * I found this tutorial very helpful
     * http://wpsmith.net/2011/how-to-create-a-custom-form-field-in-gravity-forms-with-a-terms-of-service-form-field-example/
     *
     * @param $field_groups
     *
     * @return mixed
     */

    public function hps_add_ach_field($field_groups) {
        foreach ($field_groups as &$group) {
            if ($group["name"] == "pricing_fields") {
                $group["fields"][] = array(
                    'class' => 'button',
                    // this has to match
                    // \GF_Field_HPSACH::$type
                    'data-type' => 'hpsACH',
                    // the first param here will be the button text
                    // leave the second one as gravityforms
                    'value' => __('Secure ACH', "gravityforms"),
                    "onclick" => "StartAddField('hpsACH');");
                break;
            }
        }

        return $field_groups;
    }
    /** Add our Secure CC button to the pricing fields.
     * I found this tutorial very helpful
     * http://wpsmith.net/2011/how-to-create-a-custom-form-field-in-gravity-forms-with-a-terms-of-service-form-field-example/
     *
     * @param $field_groups
     *
     * @return mixed
     */

    public function hps_add_cc_field($field_groups) {
        foreach ($field_groups as &$group) {
            if ($group["name"] == "pricing_fields") {
                $group["fields"][] = array(
                    'class' => 'button',
                    // this has to match
                    // \GF_Field_HPSACH::$type
                    'data-type' => 'hpscreditcard',
                    // the first param here will be the button text
                    // leave the second one as gravityforms
                    'value' => __('Secure CC', "gravityforms"),
                    "onclick" => "StartAddField('hpscreditcard');"
                );
                break;
            }
        }

        return $field_groups;
    }
    /**
     *
     */
    public function init() {
        parent::init();
        add_action('gform_post_payment_completed',
                   array(
                       $this,
                       'updateAuthorizationEntry'),
                   10,
                   2);
        add_filter('gform_replace_merge_tags',
                   array(
                       $this,
                       'replaceMergeTags'),
                   10,
                   7);
        add_action('gform_admin_pre_render',
                   array(
                       $this,
                       'addClientSideMergeTags'));

        /*
        * * Sets WP to call \GFSecureSubmit::hps_add_ach_field and build our button
         src: wordpress/wp-includes/plugin.php
        * \GFSecureSubmit::hps_add_ach_field
        */
        add_filter('gform_add_field_buttons',
            array(
                $this,
                'hps_add_ach_field'));
        add_filter('gform_add_field_buttons',
            array(
                $this,
                'hps_add_cc_field'));
        add_action('gform_editor_js_set_default_values', array($this, 'set_defaults'));
    }
    /**
     *
     */
    public function set_defaults() {
        // this hook is fired in the middle of a JavaScript switch statement,
        // so we need to add a case for our new field types
        ?>
        case "hpsACH" :
            field.label = "Bank Transfer"; //setting the default field label
            break;
        case "hpscreditcard" :
            field.label = "Secure Credit Card"; //setting the default field label
            break;
        <?php
    }
    /**
     *
     */
    public function init_ajax() {
        parent::init_ajax();
        add_action('wp_ajax_gf_validate_secret_api_key',
                   array(
                       $this,
                       'ajaxValidateSecretApiKey'
                   ));
    }
    /**
     *
     */
    public function ajaxValidateSecretApiKey() {
        $this->includeSecureSubmitSDK();
        $config = new HpsServicesConfig();
        $config->secretApiKey = rgpost('key');
        $config->developerId = '002914';
        $config->versionNumber = '1916';

        $service = new HpsCreditService($config);

        $is_valid = true;

        try {
            $service->get('1');
        } catch (HpsAuthenticationException $e) {
            $is_valid = false;
        } catch (HpsException $e) {
            // Transaction was authenticated, but failed for another reason
        }

        $response = $is_valid
            ? 'valid'
            : 'invalid';
        die($response);
    }
    /**
     * @return array
     */
    public function plugin_settings_fields() {
        return array(
            array(
                'title' => __('SecureSubmit API', $this->_slug),
                'fields' => $this->sdkSettingsFields(),),
            array(
                'title' => __('Velocity Limits', $this->_slug),
                'fields' => $this->vmcSettingsFields(),),);
    }
    /**
     * @return bool|false|string
     */
    public function feed_list_message() {
         if ( $this->_requires_credit_card && (! $this->has_hps_payment_fields())  ) {
             return $this->requires_credit_card_message();
         }

        // from GFFeedAddOn::feed_list_message
        if (!$this->can_create_feed()) {
            return $this->configure_addon_message();
        }

        return false;
    }
    /**
     * @return bool
     */
    private function has_hps_payment_fields(){
        $fields = GFAPI::get_fields_by_type( $this->get_current_form(), $this->processPaymentsFor );
        return empty( $fields ) ? false : true;
    }
    /**
     * @param $form
     *
     * @return bool
     */
    private function has_ach_field( $form ) {
        return $this->get_ach_field( $form ) !== false;
    }
    /**
     * @param $form
     *
     * @return bool
     */
    private function has_credit_card_fields($form) {
        if (empty($this->isCC)) {
            $fields = GFAPI::get_fields_by_type( $form, $this->ccFields );
            $this->isCC = empty( $fields ) ? false : true;
        }
        return $this->isCC;
    }
    /**
     * @return array
     */
    public function vmcSettingsFields() {
        return array(
            array(
                'name' => 'enable_fraud',
                'label' => __('Velocity Settings', $this->_slug),
                'type' => 'select',
                'default_value' => 'Enabled',
                'tooltip' => __('Choose whether you wish to limit failed attempts', $this->_slug),
                'choices' => array(
                    array(
                        'label' => __('Enabled', $this->_slug),
                        'value' => 'true',
                        'selected' => true,),
                    array(
                        'label' => __('Disabled', $this->_slug),
                        'value' => 'false',),),),
            array(
                'name' => 'fraud_message',
                'label' => __('Displayed Message', $this->_slug),
                'type' => 'text',
                'tooltip' => __('Text entered here will be displayed to your consumer if they exceed the failures within the timeframe.',
                                $this->_slug),
                'default_value' => 'Please contact us to complete the transaction.',
                'class' => 'medium',),
            array(
                'name' => 'fraud_velocity_attempts',
                'label' => __('How many failed attempts before blocking?', $this->_slug),
                'type' => 'text',
                'default_value' => '3',
                'class' => 'small',),
            array(
                'name' => 'fraud_velocity_timeout',
                'label' => __('How long (in minutes) should we keep a tally of recent failures?',
                              $this->_slug),
                'type' => 'text',
                'default_value' => '10',
                'class' => 'small',),);
    }
    /**
     * @return array
     */
    public function sdkSettingsFields() {
        return array(
            array(
                'name' => 'public_api_key',
                'label' => __('Public Key', $this->_slug),
                'type' => 'text',
                'class' => 'medium',
                'onchange' => "SecureSubmitAdmin.validateKey('public_api_key', this.value);",),
            array(
                'name' => 'secret_api_key',
                'label' => __('Secret Key', $this->_slug),
                'type' => 'text',
                'class' => 'medium',
                'onchange' => "SecureSubmitAdmin.validateKey('secret_api_key', this.value);",),
            array(
                'name' => 'authorize_or_charge',
                'label' => __('Payment Action', $this->_slug),
                'type' => 'select',
                'default_value' => 'capture',
                'tooltip' => __('Choose whether you wish to capture funds immediately or authorize payment only.',
                                $this->_slug),
                'choices' => array(
                    array(
                        'label' => __('Capture', $this->_slug),
                        'value' => 'capture',
                        'selected' => true,),
                   array(
                        'label' => __('Authorize', $this->_slug),
                        'value' => 'authorize',),),),
            array(
                'name' => 'allow_payment_action_override',
                'label' => __('Allow Payment Action Override', $this->_slug),
                'type' => 'radio',
                'default_value' => 'no',
                'tooltip' => __('Allows a SecureSubmit Feed to override the default payment action (authorize / capture).',
                                $this->_slug),
                'choices' => array(
                    array(
                        'label' => __('No', $this->_slug),
                        'value' => 'no',
                        'selected' => true,),
                    array(
                        'label' => __('Yes', $this->_slug),
                        'value' => 'yes',),),
                'horizontal' => true,),
            array(
                'name' => 'allow_level_ii',
                'label' => __('Allow Level II Processing', $this->_slug),
                'type' => 'radio',
                'default_value' => 'no',
                'tooltip' => __('If you need Level II Processing, enable this field.', $this->_slug),
                'choices' => array(
                    array(
                        'label' => __('No', $this->_slug),
                        'value' => 'no',
                        'selected' => true,),
                    array(
                        'label' => __('Yes', $this->_slug),
                        'value' => 'yes',),),
                'horizontal' => true,),
            array(
                'name' => 'allow_api_keys_override',
                'label' => __('Allow API Keys Override', $this->_slug),
                'type' => 'radio',
                'default_value' => 'no',
                'tooltip' => __('Allows a SecureSubmit Feed to override the default set of API keys.',
                                $this->_slug),
                'choices' => array(
                    array(
                        'label' => __('No', $this->_slug),
                        'value' => 'no',
                        'selected' => true,),
                    array(
                        'label' => __('Yes', $this->_slug),
                        'value' => 'yes',),),
                'horizontal' => true,),
            array(
                'name' => 'send_email',
                'label' => __('Send Email', $this->_slug),
                'type' => 'radio',
                'default_value' => 'no',
                'tooltip' => __('Sends email with transaction details independent of GF notification system.',
                                $this->_slug),
                'choices' => array(
                    array(
                        'label' => __('No', $this->_slug),
                        'value' => 'no',
                        'selected' => true,),
                    array(
                        'label' => __('Yes', $this->_slug),
                        'value' => 'yes',),),
                'horizontal' => true,
                'onchange' => "SecureSubmitAdmin.toggleSendEmailFields(this.value);",),
            array(
                'name' => 'send_email_recipient_address',
                'label' => __('Email Recipient', $this->_slug),
                'type' => 'text',
                'class' => 'medium',),
            array(
                'label' => 'hidden',
                'name' => 'public_api_key_is_valid',
                'type' => 'hidden',),
            array(
                'label' => 'hidden',
                'name' => 'secret_api_key_is_valid',
                'type' => 'hidden',),);
    }
    /**
     * @return array
     */
    public function feed_settings_fields() {
        $default_settings = parent::feed_settings_fields();

        if ($this->getAllowPaymentActionOverride() == 'yes') {
            $authorize_or_charge_field = array(
                'name' => 'authorize_or_charge',
                'label' => __('Payment Action', $this->_slug),
                'type' => 'select',
                'default_value' => 'capture',
                'tooltip' => __('Choose whether you wish to capture funds immediately or authorize payment only.',
                                $this->_slug),
                'choices' => array(
                                  array(
                        'label' => __('Capture', $this->_slug),
                        'value' => 'capture',),
                array(
                        'label' => __('Authorize', $this->_slug),
                        'value' => 'authorize',),),);
            if ($this->getAuthorizeOrCharge() == 'capture') {
                $authorize_or_charge_field['choices'][0]['selected'] = true;
            }
            else {
                $authorize_or_charge_field['choices'][1]['selected'] = true;
            }
            $default_settings = $this->add_field_after('paymentAmount', $authorize_or_charge_field, $default_settings);
        }
        if ($this->getAllowAPIKeysOverride() == 'yes') {
            $public_api_key_field = array(
                'name' => 'public_api_key',
                'label' => __('Public Key', $this->_slug),
                'type' => 'text',
                'class' => 'medium',
                'onchange' => "SecureSubmitAdmin.validateKey('public_api_key', this.value);",);
            $secret_api_key_field = array(
                'name' => 'secret_api_key',
                'label' => __('Secret Key', $this->_slug),
                'type' => 'text',
                'class' => 'medium',
                'onchange' => "SecureSubmitAdmin.validateKey('secret_api_key', this.value);",);
            $default_settings = $this->add_field_after('paymentAmount', $public_api_key_field, $default_settings);
            $default_settings = $this->add_field_after('paymentAmount', $secret_api_key_field, $default_settings);
        }

        if ($this->getAllowLevelII() == 'yes') {
            $tax_type_field = array(
                'name' => 'mappedFields',
                'label' => esc_html__('Level II Mapping', $this->_slug),
                'type' => 'field_map',
                'field_map' => $this->get_level_ii_fields(),
                'tooltip' => '<h6>' . esc_html__('Map Fields',
                                                 $this->_slug) . '</h6>' . esc_html__('This is only required if you plan to do Level II Processing.',
                                                                                      $this->_slug),);

            $default_settings = $this->add_field_after('paymentAmount', $tax_type_field, $default_settings);
        }

        return $default_settings;
    }
    /**
     * @return array
     */
    protected function get_level_ii_fields() {
        $fields = array(
            array(
                "name" => "customerpo",
                "label" => __("Customer PO", "gravityforms"),
                "required" => false),
            array(
                "name" => "taxtype",
                "label" => __("Tax Type", "gravityforms"),
                "required" => false),
            array(
                "name" => "taxamount",
                "label" => __("Tax Amount", "gravityforms"),
                "required" => false),);

        return $fields;
    }
    /**
     * @return array
     */
    public function scripts() {
        $scripts = array(
            array(
                'handle' => 'securesubmit.js',
                'src' => 'https://api.heartlandportico.com/SecureSubmit.v1/token/2.1/securesubmit.js',
                'version' => $this->_version,
                'deps' => array(),
                'enqueue' => array(
                    array(
                        'admin_page' => array('plugin_settings'),
                        'tab' => array($this->_slug, $this->get_short_title()),
                    ),
                ),
            ),
            array(
                'handle' => 'gforms_securesubmit_frontend',
                'src' => $this->get_base_url() . '/../assets/js/securesubmit.js',
                'version' => $this->_version,
                'deps' => array('jquery', 'securesubmit.js'),
                'in_footer' => false,
                'enqueue' => array(
                    array($this, 'hasFeedCallback'),
                ),
            ),
            array(
                'handle' => 'gform_json',
                'src' => GFCommon::get_base_url() . '/js/jquery.json-1.3.js',
                'version' => $this->_version,
                'deps' => array('jquery'),
                'in_footer' => false,
                'enqueue' => array(
                    array($this, 'hasFeedCallback'),
                ),
            ),
            array(
                'handle' => 'gforms_securesubmit_admin',
                'src' => $this->get_base_url() . '/../assets/js/securesubmit-admin.js',
                'version' => $this->_version,
                'deps' => array('jquery'),
                'in_footer' => false,
                'enqueue' => array(
                    array('admin_page' => array('plugin_settings', 'form_editor'), 'tab' => array($this->_slug, $this->get_short_title())),
                ),
                'strings' => array(
                    'spinner' => GFCommon::get_base_url() . '/images/spinner.gif',
                    'validation_error' => __('Error validating this key. Please try again later.', 'gravityforms-securesubmit'),
                ),
            ),
        );

        return array_merge(parent::scripts(), $scripts);


    }
    /**
     * @return array
     */
    public function styles() {
        $styles = array(
            array(
                'handle' => 'securesubmit_css',
                'src' => $this->get_base_url() . "/../css/style.css",
                'version' => $this->_version,
                'enqueue' => array(
                    array(
                        $this,
                        'hasFeedCallback'),),),);

        return array_merge(parent::styles(), $styles);
    }
    /**
     *
     */
    public function add_theme_scripts() {

        wp_enqueue_style('style', $this->get_base_url() . '/../css/style.css', array(), '1.1', 'all');

        if (is_singular() && comments_open() && get_option('thread_comments')) {
            wp_enqueue_script('comment-reply');
        }
    }
    /**
     *
     */

    public function init_frontend() {
        add_filter('gform_register_init_scripts',
            array(
                       $this,
                       'registerInitScripts'),
                   10,
                   3);
        add_filter('gform_field_content',
            array(
                       $this,
                       'addSecureSubmitInputs'),
                   10,
                   5);
        parent::init_frontend();

    }
    /**
     * @param $form
     * @param $field_values
     * @param $is_ajax
     */
    public function registerInitScripts($form, $field_values, $is_ajax) {
        if (!$this->has_feed($form['id'])) {
            return;
        }
        if (!$this->has_credit_card_fields($form)) {
            return;
        }

        $feeds = GFAPI::get_feeds(null, $form['id']);
        $feed = $feeds[0];
        $pubKey = $this->getPublicApiKey($feed);
        $cc_field = $this->get_credit_card_field($form);

        if ($cc_field === false) {
            $cc_field = $this->get_hpscredit_card_field($form);
        }

        $args = array(
            'apiKey' => $pubKey,
            'formId' => $form['id'],
            'ccFieldId' => $cc_field['id'],
            'ccPage' => rgar($cc_field, 'pageNumber'),
            'isAjax' => $is_ajax,
            'isSecure' => $cc_field['type'] === 'hpscreditcard',
            'baseUrl' => plugins_url( '', dirname(__FILE__) . '../' ),
        );
        $script = 'new window.SecureSubmit(' . json_encode($args) . ');';
        GFFormDisplay::add_init_script($form['id'], 'securesubmit', GFFormDisplay::ON_PAGE_RENDER, $script);
    }


    /**
     * @param $content
     * @param $field
     * @param $value
     * @param $lead_id
     * @param $form_id
     *
     * @return string
     */
    public function addSecureSubmitInputs($content, $field, $value, $lead_id, $form_id) {
        $type = GFFormsModel::get_input_type($field);
        $secureSubmitFieldFound = in_array($type, $this->processPaymentsFor);
        $hasFeed = $this->has_feed($form_id);
        if (! $secureSubmitFieldFound) {
            return $content;
        }
        else{
            if ($this->getSecureSubmitJsResponse()) {
                $content .= '<input type=\'hidden\' name=\'securesubmit_response\' id=\'gf_securesubmit_response\' value=\'' . rgpost('securesubmit_response') . '\' />';
            }
            if (!$hasFeed && $secureSubmitFieldFound) { // Style sheet wont have loaded
                $fieldLabel = $field->label;
                $content = '<span style="color:#ce1025 !important;padding-left:3px;font-size:20px !important;font-weight:700 !important;">Your ['.$fieldLabel.'] seems to be missing a feed. Please check your configuration!!</span>';
            }
        }

        return $content;
    }
    /**
     * @param array $validationResult
     *
     * @return array
     */
    public function maybe_validate($validationResult) {
        if (!$this->has_feed($validationResult['form']['id'], true)) {
            return $validationResult;
        }

        foreach ($validationResult['form']['fields'] as $field) {
            $currentPage = GFFormDisplay::get_source_page($validationResult['form']['id']);
            $fieldOnCurrentPage = $currentPage > 0 && $field['pageNumber'] == $currentPage;
            $fieldType = GFFormsModel::get_input_type($field);

            if (!in_array($fieldType, $this->processPaymentsFor) || !$fieldOnCurrentPage) {
                continue;
            }

            if (false == $this->validateACH() && $this->getSecureSubmitJsError() && $this->hasPayment($validationResult)) {
                $field['failed_validation'] = true;
                $field['validation_message'] = "The following error occured: [".$this->getSecureSubmitJsError() . "]";
            }
            else {
                $field['failed_validation'] = false;
            }

            break;
        }

        $validationResult['is_valid'] = !$field['failed_validation'];

        return parent::maybe_validate($validationResult);
    }
    /**
     * @param $validation_result
     *
     * @return mixed
     */
    public function validation($validation_result)
    {
        if (!rgar($validation_result['form'], 'id', false)) {
            return $validation_result;
        }
        if (!$this->has_feed($validation_result['form']['id'], true)) {
            return $validation_result;
        }

        $this->isACH = false;
        $this->isCC = false;
        foreach ($validation_result['form']['fields'] as $field) {
            $current_page = GFFormDisplay::get_source_page($validation_result['form']['id']);
            $field_on_curent_page = $current_page > 0 && $field['pageNumber'] == $current_page;
            $fieldType = GFFormsModel::get_input_type($field);

            if ($fieldType == 'hpsACH' && $field_on_curent_page) {
                $this->isACH = $field;
            }
            if (in_array($fieldType, $this->ccFields) && $field_on_curent_page) {
                $this->isCC = $field;
                if (false == $this->validateACH() && $this->getSecureSubmitJsError() && $this->hasPayment($validation_result)) {
                    $field['failed_validation'] = true;
                    $field['validation_message'] = $this->getSecureSubmitJsError();
                }
                else {
                    // override validation in case user has marked field as required allowing securesubmit to handle cc validation
                    $field['failed_validation'] = false;
                }
            }
        }
        // revalidate the validation result
        $validation_result['is_valid'] = true;
        foreach ($validation_result['form']['fields'] as $field) {
            if (in_array($field['type'], $this->processPaymentsFor)
                && false !== $this->isACH
                && false !== $this->isCC
                && false === $this->isCC->failed_validation
            ) {
                continue;
            }

            if ($field['failed_validation']) {
                $validation_result['is_valid'] = false;
                break;
            }
        }

        return parent::validation($validation_result);
    }
    /**
 *
 * @param $feed - Current configured payment feed
 * @param $submission_data - Contains form field data submitted by the user as well as payment information (i.e. payment amount, setup fee, line items, etc...)
 * @param $form - Current form array containing all form settings
 * @param $entry - Current entry array containing entry information (i.e data submitted by users). NOTE: the entry hasn't been saved to the database at this point, so this $entry object does not have the 'ID' property and is only a memory representation of the entry.
 *
 * @return array - Return an $authorization array in the following format:
 * [
 *  'is_authorized' => true|false,
 *  'error_message' => 'Error message',
 *  'transaction_id' => 'XXX',
 *
 *  //If the payment is captured in this method, return a 'captured_payment' array with the following information about the payment
 *  'captured_payment' => ['is_success'=>true|false, 'error_message' => 'error message', 'transaction_id' => 'xxx', 'amount' => 20]
 * ]
 */
    public function authorize($feed, $submission_data, $form, $entry) {


        $auth = array(
            'is_authorized' => false,
            'captured_payment' => array('is_success' => false),
        );
        $this->includeSecureSubmitSDK();

        $submission_data = array_merge($submission_data, $this->get_submission_dataACH($feed, $form, $entry));
        $isCCData = $this->getSecureSubmitJsResponse();

        if (empty($isCCData->token_value) && false !== $this->isACH && !empty($submission_data['ach_number'])) {
            $auth = $this->authorizeACH($feed, $submission_data, $form, $entry);
        } elseif (empty($submission_data['ach_number']) && false !== $this->isCC && !empty($isCCData->token_value)) {
            $auth = $this->authorizeCC($feed, $submission_data, $form, $entry);
        } else {
            $failMessage = __('Please check your entries and submit only Credit Card or Bank Transfer');
            $auth = $this->authorization_error($failMessage);
        }

        return $auth;
    }
    /**
     * @param $feed
     *
     * @return \HpsServicesConfig
     */
    private function hpsServices($feed) {
        $config = new HpsServicesConfig();
        $config->secretApiKey = $this->getSecretApiKey($feed);
        $config->developerId = '002914';
        $config->versionNumber = '1916';

        return $config;
    }

    /**
     *
     * @param $feed - Current configured payment feed
     * @param $submission_data - Contains form field data submitted by the user as well as payment information (i.e. payment amount, setup fee, line items, etc...)
     * @param $form - Current form array containing all form settings
     * @param $entry - Current entry array containing entry information (i.e data submitted by users). NOTE: the entry hasn't been saved to the database at this point, so this $entry object does not have the 'ID' property and is only a memory representation of the entry.
     *
     * @return array - Return an $authorization array in the following format:
     * [
     *  'is_authorized' => true|false,
     *  'error_message' => 'Error message',
     *  'transaction_id' => 'XXX',
     *
     *  //If the payment is captured in this method, return a 'captured_payment' array with the following information about the payment
     *  'captured_payment' => ['is_success'=>true|false, 'error_message' => 'error message', 'transaction_id' => 'xxx', 'amount' => 20]
     * ]
     */
    private function authorizeACH($feed, $submission_data, $form, $entry) {
        $note = null;

        /** Currently saved plugin settings */
        $settings = $this->get_plugin_settings();

        /** This is the message show to the consumer if the rule is flagged */
        $fraud_message = (string)$this->get_setting("fraud_message",
            'Please contact us to complete the transaction.',
            $settings);

        /** Maximum number of failures allowed before rule is triggered */
        $fraud_velocity_attempts = (int)$this->get_setting("fraud_velocity_attempts", '3', $settings);

        /** Maximum amount of time in minutes to track failures. If this amount of time elapse between failures then the counter($HeartlandHPS_FailCount) will reset */
        $fraud_velocity_timeout = (int)$this->get_setting("fraud_velocity_timeout", '10', $settings);

        /** Variable name with hash of IP address to identify uniqe transient values         */
        $HPS_VarName = (string)"HeartlandHPS_Velocity_" . md5($this->getRemoteIP());

        /** Running count of failed transactions from the current IP*/
        $HeartlandHPS_FailCount = (int)get_transient($HPS_VarName);

        /** Defaults to true or checks actual settings for this plugin from $settings. If true the following settings are applied:
         *
         * $fraud_message
         *
         * $fraud_velocity_attempts
         *
         * $fraud_velocity_timeout
         *
         */
        $enable_fraud = (bool)($this->get_setting("enable_fraud", 'true', $settings) === 'true');




        /** @var HpsFluentCheckService $service */
        /** @var HpsCheckResponse $response */
        /** @var HpsCheck $check */
        /** @var string $note displayed message for consumer */

        $check = new HpsCheck();
        $check->accountNumber = $submission_data['ach_number']; // from form $account_number_field_input
        $check->routingNumber = $submission_data['ach_route'];  // from form $routing_number_field_input

        $check->checkHolder = $this->buildCheckHolder($feed, $submission_data, $entry);//$account_name_field_input
        $check->secCode = HpsSECCode::WEB;
        $check->dataEntryMode = HpsDataEntryMode::MANUAL;
        $check->checkType
            = $submission_data['ach_check_type']; //HpsCheckType::BUSINESS; // drop down choice PERSONAL or BUSINESS $check_type_input
        $check->accountType
            = $submission_data['ach_account_type']; //HpsAccountType::CHECKING; // drop down choice CHECKING or SAVINGS $account_type_input
        $config = new HpsServicesConfig();
        $config->secretApiKey = $this->getSecretApiKey($feed);
        $config->developerId = '002914';
        $config->versionNumber = '1916';

        $service = new HpsFluentCheckService($config);
        try {
            /**
             * if fraud_velocity_attempts is less than the $HeartlandHPS_FailCount then we know
             * far too many failures have been tried
             */
            if ($enable_fraud && $HeartlandHPS_FailCount >= $fraud_velocity_attempts) {
                sleep(5);
                $issuerResponse = (string)get_transient($HPS_VarName . 'IssuerResponse');
                return $this->authorization_error(wp_sprintf('%s %s', $fraud_message, $issuerResponse));
                //throw new HpsException(wp_sprintf('%s %s', $fraud_message, $issuerResponse));
            }
            $response = $service->sale($submission_data['payment_amount'])
                ->withCheck($check)/**@throws HpsCheckException on error */
                ->execute();
            $type = 'Payment';
            $amount_formatted = GFCommon::to_money($submission_data['payment_amount'], GFCommon::get_currency());
            $note = sprintf(__('%s has been completed. Amount: %s. Transaction Id: %s.', $this->_slug),
                            $type,
                            $amount_formatted,
                            $response->transactionId);

            $auth = array(
                'is_authorized' => true,
                'captured_payment' => array(
                    'is_success' => true,
                    'transaction_id' => $response->transactionId,
                    'amount' => $submission_data['payment_amount'],
                    'payment_method' => 'ACH',
                    'securesubmit_payment_action' => 'checkSale',
                    'note' => $note,
                ),
            );
        } catch (HpsCheckException $e) {
            $err = null;
            if (is_array($e->details)) {
                foreach ($e->details as $error) {
                    if ($error->messageType === 'Error') {
                        $err .= $error->message . "\r\n";
                    }
                }
            }
            else {
                $err .= $e->details->message . "\r\n";
            }
            // if advanced fraud is enabled, increment the error count
            if ($enable_fraud) {
                if (empty($HeartlandHPS_FailCount)) {
                    $HeartlandHPS_FailCount = 0;
                }
                set_transient($HPS_VarName, $HeartlandHPS_FailCount + 1, MINUTE_IN_SECONDS * $fraud_velocity_timeout);
                if ($HeartlandHPS_FailCount < $fraud_velocity_attempts) {
                    set_transient($HPS_VarName . 'IssuerResponse',
                        $err,
                        MINUTE_IN_SECONDS * $fraud_velocity_timeout);
                }
            }
            $auth = $this->authorization_error($err);
            $auth['transaction_id'] = (string)$e->transactionId;
        } catch (HpsException $e) {
            // if advanced fraud is enabled, increment the error count
            if ($enable_fraud) {
                if (empty($HeartlandHPS_FailCount)) {
                    $HeartlandHPS_FailCount = 0;
                }
                set_transient($HPS_VarName, $HeartlandHPS_FailCount + 1, MINUTE_IN_SECONDS * $fraud_velocity_timeout);
                if ($HeartlandHPS_FailCount < $fraud_velocity_attempts) {
                    set_transient($HPS_VarName . 'IssuerResponse',
                                  $e->getMessage(),
                                  MINUTE_IN_SECONDS * $fraud_velocity_timeout);
                }
            }
            $auth = $this->authorization_error($e->getMessage());
        }
        return $auth;
    }
    /**
     * @param $form
     *
     * @return bool|\GF_Field
     */
    private function get_ach_field($form) {
        $fields = GFAPI::get_fields_by_type($form, array('ach'));
        return empty($fields) ? false : $fields[0];
    }
    /**
     * @param $form
     *
     * @return bool|\GF_Field
     */
    private function get_hpscredit_card_field($form){
        $fields = GFAPI::get_fields_by_type( $form, array( 'hpscreditcard' ) );
        return empty( $fields ) ? false : $fields[0];
    }
    /**
     * @param $feed
     * @param $form
     * @param $entry
     *
     * @return mixed
     */
    public function get_submission_dataACH($feed, $form, $entry) {
        $this_id = $feed['id'];

        $submission_data = $this->current_submission_data;

        $submission_data['ach_number'] = $this->validateACH();
        $submission_data['ach_route'] = $this->remove_spaces_from_card_number(rgpost(GF_Field_HPSach::HPS_ACH_ROUTING_FIELD_NAME));
        $submission_data['ach_account_type'] = '';
        $submission_data['ach_check_type'] = '';
        $submission_data['ach_check_holder'] = '';

        $accountType = rgpost(GF_Field_HPSach::HPS_ACH_TYPE_FIELD_NAME);
        $checkType = rgpost(GF_Field_HPSach::HPS_ACH_CHECK_FIELD_NAME);
        $accountTypeOptions = array( 1=>
            HpsAccountType::CHECKING,
            HpsAccountType::SAVINGS);
        $checkTypeOptions = array(1=>
            HpsCheckType::PERSONAL,
            HpsCheckType::BUSINESS);
        if (key_exists($accountType, $accountTypeOptions) && key_exists($checkType, $checkTypeOptions)) {
            $submission_data['ach_account_type']
                = $accountTypeOptions[ $accountType ];//HpsAccountType::CHECKING; drop down choice CHECKING or SAVINGS $account_type_input
            $submission_data['ach_check_type']
                = $checkTypeOptions[ $checkType ];//HpsCheckType::BUSINESS; drop down choice PERSONAL or BUSINESS $check_type_input

        }
        $submission_data['ach_check_holder'] = rgpost(GF_Field_HPSach::HPS_ACH_CHECK_HOLDER_FIELD_NAME);

        return gf_apply_filters(array('gform_submission_data_pre_process_payment', $form['id']), $submission_data, $feed, $form, $entry);
    }
    /**
     * @return mixed|null
     */
    private function validateACH() {
        $value = $this->remove_spaces_from_card_number(rgpost(GF_Field_HPSach::HPS_ACH_ACCOUNT_FIELD_NAME));
        $isValid = preg_match('/^[\d]{4,17}$/', $value) === 1;

        return $isValid ? $value : null;

    }

    /**
     *
     * @param $feed - Current configured payment feed
     * @param $submission_data - Contains form field data submitted by the user as well as payment information (i.e. payment amount, setup fee, line items, etc...)
     * @param $form - Current form array containing all form settings
     * @param $entry - Current entry array containing entry information (i.e data submitted by users). NOTE: the entry hasn't been saved to the database at this point, so this $entry object does not have the 'ID' property and is only a memory representation of the entry.
     *
     * @return array - Return an $authorization array in the following format:
     * [
     *  'is_authorized' => true|false,
     *  'error_message' => 'Error message',
     *  'transaction_id' => 'XXX',
     *
     *  //If the payment is captured in this method, return a 'captured_payment' array with the following information about the payment
     *  'captured_payment' => ['is_success'=>true|false, 'error_message' => 'error message', 'transaction_id' => 'xxx', 'amount' => 20]
     * ]
     */
    private function authorizeCC($feed, $submission_data, $form, $entry) {
        $this->populateCreditCardLastFour($form);

        /** Currently saved plugin settings */
        $settings = $this->get_plugin_settings();

        /** This is the message show to the consumer if the rule is flagged */
        $fraud_message = (string)$this->get_setting("fraud_message",
                                                    'Please contact us to complete the transaction.',
                                                    $settings);

        /** Maximum number of failures allowed before rule is triggered */
        $fraud_velocity_attempts = (int)$this->get_setting("fraud_velocity_attempts", '3', $settings);

        /** Maximum amount of time in minutes to track failures. If this amount of time elapse between failures then the counter($HeartlandHPS_FailCount) will reset */
        $fraud_velocity_timeout = (int)$this->get_setting("fraud_velocity_timeout", '10', $settings);

        /** Variable name with hash of IP address to identify uniqe transient values         */
        $HPS_VarName = (string)"HeartlandHPS_Velocity_" . md5($this->getRemoteIP());

        /** Running count of failed transactions from the current IP*/
        $HeartlandHPS_FailCount = (int)get_transient($HPS_VarName);

        /** Defaults to true or checks actual settings for this plugin from $settings. If true the following settings are applied:
         *
         * $fraud_message
         *
         * $fraud_velocity_attempts
         *
         * $fraud_velocity_timeout
         *
         */
        $enable_fraud = (bool)($this->get_setting("enable_fraud", 'true', $settings) === 'true');

        if ($this->getSecureSubmitJsError()) {
            return $this->authorization_error($this->getSecureSubmitJsError());
        }

        $isAuth = $this->getAuthorizeOrCharge($feed) == 'authorize';
        $config = new HpsServicesConfig();
        $config->secretApiKey = $this->getSecretApiKey($feed);
        $config->developerId = '002914';
        $config->versionNumber = '1916';

        $service = new HpsCreditService($config);

        $cardHolder = $this->buildCardHolder($feed, $submission_data, $entry);

        try {

            /**
             * if fraud_velocity_attempts is less than the $HeartlandHPS_FailCount then we know
             * far too many failures have been tried
             */
            if ($enable_fraud && $HeartlandHPS_FailCount >= $fraud_velocity_attempts) {
                sleep(5);
                $issuerResponse = (string)get_transient($HPS_VarName . 'IssuerResponse');
                return $this->authorization_error(wp_sprintf('%s %s', $fraud_message, $issuerResponse));
                //throw new HpsException(wp_sprintf('%s %s', $fraud_message, $issuerResponse));
            }
            $response = $this->getSecureSubmitJsResponse();
            $token = new HpsTokenData();
            $token->tokenValue = ($response != null
                ? $response->token_value
                : '');

            $transaction = null;
            if ($isAuth) {
                if ($this->getAllowLevelII()) {
                    $transaction = $service->authorize($submission_data['payment_amount'],
                                                       GFCommon::get_currency(),
                                                       $token,
                                                       $cardHolder,
                                                       false,
                                                       null,
                                                       null,
                                                       false,
                                                       true);
                }
                else {
                    $transaction = $service->authorize($submission_data['payment_amount'],
                                                       GFCommon::get_currency(),
                                                       $token,
                                                       $cardHolder);
                }
            }
            else {
                if ($this->getAllowLevelII()) {
                    $transaction = $service->charge($submission_data['payment_amount'],
                                                    GFCommon::get_currency(),
                                                    $token,
                                                    $cardHolder,
                                                    false,
                                                    null,
                                                    null,
                                                    false,
                                                    true,
                                                    null);
                }
                else {
                    $transaction = $service->charge($submission_data['payment_amount'],
                                                    GFCommon::get_currency(),
                                                    $token,
                                                    $cardHolder);
                }
            }
            self::get_instance()->transaction_response = $transaction;

            if ($this->getSendEmail() == 'yes') {
                $this->sendEmail($form, $entry, $transaction, $cardHolder);
            }

            $type = $isAuth
                ? 'Authorization'
                : 'Payment';
            $amount_formatted = GFCommon::to_money($submission_data['payment_amount'], GFCommon::get_currency());
            $note = sprintf(__('%s has been completed. Amount: %s. Transaction Id: %s.', $this->_slug),
                            $type,
                            $amount_formatted,
                            $transaction->transactionId);

            if ($this->getAllowLevelII()
                && ($transaction->cpcIndicator == 'B' || $transaction->cpcIndicator == 'R' || $transaction->cpcIndicator == 'S')
            ) {

                $cpcData = new HpsCPCData();
                $cpcData->CardHolderPONbr = $this->getLevelIICustomerPO($feed);

                if ($this->getLevelIITaxType($feed) == "SALES_TAX") {
                    $cpcData->TaxType = HpsTaxType::SALES_TAX;
                }
                else if ($this->getLevelIITaxType($feed) == "NOTUSED") {
                    $cpcData->TaxType = HpsTaxType::NOTUSED;
                }
                else if ($this->getLevelIITaxType($feed) == "TAXEXEMPT") {
                    $cpcData->TaxType = HpsTaxType::TAXEXEMPT;
                }

                $cpcData->TaxAmt = $this->getLevelIICustomerTaxAmount($feed);

                if (!empty($cpcData->CardHolderPONbr) && !empty($cpcData->TaxType) && !empty($cpcData->TaxAmt)) {
                    $cpcResponse = $service->cpcEdit($transaction->transactionId, $cpcData);
                    $note .= sprintf(__(' CPC Response Code: %s', $this->_slug), $cpcResponse->responseCode);
                }
            }

            if ($isAuth) {
                $note .= sprintf(__(' Authorization Code: %s', $this->_slug), $transaction->authorizationCode);
            }

            $auth = array(
                'is_authorized' => true,
                'captured_payment' => array(
                    'is_success' => true,
                    'transaction_id' => $transaction->transactionId,
                    'amount' => $submission_data['payment_amount'],
                    'payment_method' => $response->card_type,
                    'securesubmit_payment_action' => $this->getAuthorizeOrCharge($feed),
                    'note' => $note,
                ),
            );
        } catch (HpsException $e) {

            // if advanced fraud is enabled, increment the error count
            if ($enable_fraud) {
                if (empty($HeartlandHPS_FailCount)) {
                    $HeartlandHPS_FailCount = 0;
                }
                set_transient($HPS_VarName, $HeartlandHPS_FailCount + 1, MINUTE_IN_SECONDS * $fraud_velocity_timeout);
                if ($HeartlandHPS_FailCount < $fraud_velocity_attempts) {
                    set_transient($HPS_VarName . 'IssuerResponse',
                                  $e->getMessage(),
                                  MINUTE_IN_SECONDS * $fraud_velocity_timeout);
                }
            }
            $auth = $this->authorization_error($e->getMessage());
        }

        return $auth;
    }

    // Helper functions

    /**
     * @param       $entry
     * @param array $result
     *
     * @return mixed
     */
    public function updateAuthorizationEntry($entry, $result = array()) {
        if (isset($result['securesubmit_payment_action'])
            && $result['securesubmit_payment_action'] == 'authorize'
            && isset($result['is_success'])
            && $result['is_success']
        ) {
            $entry['payment_status'] = __('Authorized', $this->_slug);
            GFAPI::update_entry($entry);
        }

        return $entry;
    }
    /**
     * @param      $form
     * @param      $entry
     * @param      $transaction
     * @param null $cardHolder
     */
    protected function sendEmail($form, $entry, $transaction, $cardHolder = null) {
        $to = $this->getSendEmailRecipientAddress();
        $subject = 'New Submission: ' . $form['title'];
        $message
            = 'Form: ' . $form['title'] . ' (' . $form['id'] . ")\r\n" . 'Entry ID: ' . $entry['id'] . "\r\n" . "Transaction Details:\r\n" . print_r($transaction,
                                                                                                                                                     true);

        if ($cardHolder != null) {
            $message .= "Card Holder Details:\r\n" . print_r($cardHolder, true);
        }

        wp_mail($to, $subject, $message);
    }
    /**
     * @param $feed
     * @param $submission_data
     * @param $entry
     *
     * @return HpsCardHolder
     */
    protected function buildCardHolder($feed, $submission_data, $entry) {
        $firstName = '';
        $lastName = '';

        try {
            $name = explode(' ', $submission_data['card_name']);
            $firstName = $name[0];
            unset($name[0]);
            $lastName = implode(' ', $name);
        } catch (Exception $ex) {
            $firstName = $submission_data['card_name'];
        }

        $cardHolder = new HpsCardHolder();
        $cardHolder->firstName = $firstName;
        $cardHolder->lastName = $lastName;
        $cardHolder->address = $this->buildAddress($feed, $entry);;

        return $cardHolder;
    }
    /**
     * @param $feed
     * @param $submission_data
     * @param $entry
     *
     * @return HpsCheckHolder
     */
    protected function buildCheckHolder($feed, $submission_data, $entry) {

        $checkHolder = new HpsCheckHolder();
        $checkHolder->address = $this->buildAddress($feed, $entry);
        $checkHolder->checkName = $submission_data['ach_check_holder']; //'check holder';

        return $checkHolder;
    }
    /**
     * @param $feed
     * @param $entry
     *
     * @return \HpsAddress
     */
    private function buildAddress($feed, $entry) {
        $address = new HpsAddress();
        if (in_array('billingInformation_address', $feed['meta'])) {
            $address->address
                = $entry[ $feed['meta']['billingInformation_address'] ] . $entry[ $feed['meta']['billingInformation_address2'] ];
        }
        if (in_array('billingInformation_city', $feed['meta'])) {
            $address->city = $entry[ $feed['meta']['billingInformation_city'] ];
        }
        if (in_array('billingInformation_state', $feed['meta'])) {
            $address->state = $entry[ $feed['meta']['billingInformation_state'] ];
        }
        if (in_array('billingInformation_zip', $feed['meta'])) {
            $address->zip = $entry[ $feed['meta']['billingInformation_zip'] ];
        }
        if (in_array('billingInformation_country', $feed['meta'])) {
            $address->country = $entry[ $feed['meta']['billingInformation_country'] ];
        }

        return $address;
    }
    /**
     * @param $validation_result
     *
     * @return bool
     */
    public function hasPayment($validation_result) {
        $form = $validation_result['form'];
        $entry = GFFormsModel::create_lead($form);
        $feed = $this->get_payment_feed($entry, $form);

        if (!$feed) {
            return false;
        }

        $submission_data = $this->get_submission_data($feed, $form, $entry);

        //Do not process payment if payment amount is 0 or less
        return floatval($submission_data['payment_amount']) > 0;
    }
    /**
     * @param $form
     */
    public function populateCreditCardLastFour($form) {
        $cc_field = $this->get_credit_card_field($form);
        $response = $this->getSecureSubmitJsResponse();
        $_POST[ 'input_' . $cc_field['id'] . '_1' ] = 'XXXXXXXXXXXX' . ($response != null
                ? $response->last_four
                : '');
        $_POST[ 'input_' . $cc_field['id'] . '_4' ] = ($response != null
            ? $response->card_type
            : '');
    }
    /**
     *
     */
    public function includeSecureSubmitSDK() {
        require_once plugin_dir_path(__FILE__) . 'includes/Hps.php';
        do_action('gform_securesubmit_post_include_api');
    }
    /**
     * @param null $feed
     *
     * @return string
     */
    public function getSecretApiKey($feed = null) {
        return $this->getApiKey('secret', $feed);
    }
    /**
     * @param null $feed
     *
     * @return string
     */
    public
    function getLevelIICustomerPO($feed = null) {
        if ($feed != null && isset($feed['meta']["mappedFields_customerpo"])) {
            return (string)$_POST[ 'input_' . $feed["meta"]["mappedFields_customerpo"] ];
        }
        return null;
    }
    /**
     * @param null $feed
     *
     * @return string
     */
    public function getLevelIITaxType($feed = null) {
        if ($feed != null && isset($feed['meta']["mappedFields_taxtype"])) {
            return (string)$_POST[ 'input_' . $feed["meta"]["mappedFields_taxtype"] ];
        }
        return null;
    }
    /**
     * @param null $feed
     *
     * @return string
     */
    public function getLevelIICustomerTaxAmount($feed = null) {
        if ($feed != null && isset($feed['meta']["mappedFields_taxamount"])) {
            return (string)$_POST[ 'input_' . $feed["meta"]["mappedFields_taxamount"] ];
        }
        return null;
    }
    /**
     * @return string
     */
    public function getAllowLevelII() {
        $settings = $this->get_plugin_settings();

        return (string)$this->get_setting('allow_level_ii', 'no', $settings);
    }
    /**
     * @param null $feed
     *
     * @return string
     */
    public function getPublicApiKey($feed = null) {
        return $this->getApiKey('public', $feed);
    }
    /**
     * @param string $type
     * @param null   $feed
     *
     * @return string
     */
    public function getApiKey($type = 'secret', $feed = null) {
        // user needs admin privileges for this
        $api_key = $this->getQueryStringApiKey($type);
        if ($api_key && current_user_can('update_core')) {
            return $api_key;
        }

        if ($feed != null && isset($feed['meta']["{$type}_api_key"])) {
            return (string)$feed['meta']["{$type}_api_key"];
        }
        $settings = $this->get_plugin_settings();

        return (string)trim($this->get_setting("{$type}_api_key", '', $settings));
    }
    /**
     * @param string $type
     *
     * @return string
     */
    public function getQueryStringApiKey($type = 'secret') {
        return rgget($type);
    }
    /**
     * @param null $feed
     *
     * @return string
     */
    public function getAuthorizeOrCharge($feed = null) {
        if ($feed != null && isset($feed['meta']['authorize_or_charge'])) {
            return (string)$feed['meta']['authorize_or_charge'];
        }
        $settings = $this->get_plugin_settings();

        return (string)$this->get_setting('authorize_or_charge', 'charge', $settings);
    }
    /**
     * @return string
     */
    public function getAllowPaymentActionOverride() {
        $settings = $this->get_plugin_settings();

        return (string)$this->get_setting('allow_payment_action_override', 'no', $settings);
    }
    /**
     * @return string
     */
    public function getAllowAPIKeysOverride() {
        $settings = $this->get_plugin_settings();

        return (string)$this->get_setting('allow_api_keys_override', 'no', $settings);
    }
    /**
     * @return string
     */
    public function getSendEmail() {
        $settings = $this->get_plugin_settings();

        return (string)$this->get_setting('send_email', 'no', $settings);
    }
    /**
     * @return string
     */
    public function getSendEmailRecipientAddress() {
        $settings = $this->get_plugin_settings();

        return (string)$this->get_setting('send_email_recipient_address', '', $settings);
    }
    /**
     * @param $form
     *
     * @return bool
     */
    public function hasFeedCallback($form) {
        return $form && $this->has_feed($form['id']);
    }
    /**
     * @return array|mixed|object
     */
    public function getSecureSubmitJsResponse() {
        return json_decode(rgpost('securesubmit_response'));
    }
    /**
     * @return bool
     */
    public function getSecureSubmitJsError() {
        $response = $this->getSecureSubmitJsResponse();

        if (isset($response->error)) {
            return $response->error->message;
        }

        return false;
    }
    /**
     * @param $field
     * @param $parent
     */
    public function isFieldOnValidPage($field, $parent) {
        $form = $this->get_current_form();

        $mapped_field_id = $this->get_setting($field['name']);
        $mapped_field = GFFormsModel::get_field($form, $mapped_field_id);
        $mapped_field_page = rgar($mapped_field, 'pageNumber');

        $cc_field = $this->get_credit_card_field($form);
        $cc_page = rgar($cc_field, 'pageNumber');

        if ($mapped_field_page > $cc_page) {
            $this->set_field_error($field,
                                   __('The selected field needs to be on the same page as the Credit Card field or a previous page.',
                                      $this->_slug));
        }
    }
    /**
     * @param $text
     * @param $form
     * @param $entry
     * @param $url_encode
     * @param $esc_html
     * @param $nl2br
     * @param $format
     *
     * @return mixed
     */
    public function replaceMergeTags($text, $form, $entry, $url_encode, $esc_html, $nl2br, $format) {
        $mergeTags = array(
            'transactionId' => '{securesubmit_transaction_id}',
            'authorizationCode' => '{securesubmit_authorization_code}',
        );

        $gFormsKey = array('transactionId' => 'transaction_id',);

        foreach ($mergeTags as $key => $mergeTag) {
            // added for GF 1.9.x
            if (strpos($text, $mergeTag) === false || empty($entry) || empty($form)) {
                return $text;
            }

            $value = '';
            if (class_exists('GFSecureSubmit') && isset(GFSecureSubmit::get_instance()->transaction_response)) {
                $value = GFSecureSubmit::get_instance()->transaction_response->$key;
            }

            if (isset($gFormsKey[ $key ]) && empty($value)) {
                $value = rgar($entry, $gFormsKey[ $key ]);
            }

            $text = str_replace($mergeTag, $value, $text);
        }

        return $text;
    }
    /**
     * @param $form
     *
     * @return mixed
     */
    public function addClientSideMergeTags($form) {
        include plugin_dir_path(__FILE__) . '../templates/client-side-merge-tags.php';

        return $form;
    }
    /**Attempts to get real ip even if there is a proxy chain
     *
     * @return string
     */
    private function getRemoteIP() {
        $remoteIP = $_SERVER['REMOTE_ADDR'];
        if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER) && $_SERVER['HTTP_X_FORWARDED_FOR'] != '') {
            $remoteIPArray = array_values(array_filter(explode(',',
                                                               $_SERVER['HTTP_X_FORWARDED_FOR'])));
            $remoteIP = end($remoteIPArray);
        }

        return $remoteIP;
    }

   	/**
	 * Gets the payment validation result.
	 *
	 * @since  Unknown
	 * @access public
	 *
	 * @used-by GFPaymentAddOn::validation()
	 *
	 * @param array $validationResult    Contains the form validation results.
	 * @param array $authorizationResult Contains the form authorization results.
	 *
	 * @return array The validation result for the credit card field.
	 */
	public function get_validation_result($validationResult, $authorizationResult)
    {
		$ach_page = 0;
		foreach ($validationResult['form']['fields'] as $field) {
			if ($field->type == 'hpsACH') {
				$field->failed_validation  = true;
				$field->validation_message = $authorizationResult['error_message'];
				$ach_page                  = $field->pageNumber;
				break;
			}
		}
		$validationResult['credit_card_page'] = $ach_page;
		$validationResult['is_valid']         = false;
        return parent::get_validation_result($validationResult, $authorizationResult);
	}


    // # HPS SUBSCRIPTION FUNCTIONS ---------------------------------------------------------------------------------------

    /**
     * If custom meta data has been configured on the feed retrieve the mapped field values.
     *
     * @since  Unknown
     * @access public
     *
     * @used-by GFSecureSubmit::authorize_product()
     * @used-by GFSecureSubmit::capture()
     * @used-by GFSecureSubmit::process_subscription()
     * @used-by GFSecureSubmit::subscribe()
     * @uses    GFAddOn::get_field_value()
     *
     * @param array $feed  The feed object currently being processed.
     * @param array $entry The entry object currently being processed.
     * @param array $form  The form object currently being processed.
     *
     * @return array The HPS meta data.
     */
    public function get_hps_meta_data( $feed, $entry, $form ) {

        // Initialize metadata array.
        $metadata = array();

        // Find feed metadata.
        $custom_meta = rgars( $feed, 'meta/metaData' );

        if ( is_array( $custom_meta ) ) {

            // Loop through custom meta and add to metadata for HPS.
            foreach ( $custom_meta as $meta ) {

                // If custom key or value are empty, skip meta.
                if ( empty( $meta['custom_key'] ) || empty( $meta['value'] ) ) {
                    continue;
                }

                // Make the key available to the gform_HPS_field_value filter.
                $this->_current_meta_key = $meta['custom_key'];

                // Get field value for meta key.
                $field_value = $this->get_field_value( $form, $entry, $meta['value'] );

                if ( ! empty( $field_value ) ) {

                    // Trim to 500 characters, per HPS requirement.
                    $field_value = substr( $field_value, 0, 500 );

                    // Add to metadata array.
                    $metadata[ $meta['custom_key'] ] = $field_value;

                }

            }

            // Clear the key in case get_field_value() and gform_HPS_field_value are used elsewhere.
            $this->_current_meta_key = '';

        }

        return $metadata;

    }
    /**
     * Update the entry meta with the HPS Customer ID.
     *
     * @since  Unknown
     * @access public
     *
     * @uses GFSecureSubmit::get_hps_meta_data()
     * @uses GFSecureSubmit::get_customer()
     * @uses GFPaymentAddOn::process_subscription()
     * @uses \Stripe\Customer::save()
     * @uses \Exception::getMessage()
     * @uses GFAddOn::log_error()
     *
     * @param array $authorization   Contains the result of the subscribe() function.
     * @param array $feed            The feed object currently being processed.
     * @param array $submission_data The customer and transaction data.
     * @param array $form            The form object currently being processed.
     * @param array $entry           The entry object currently being processed.
     *
     * @return array The entry object.
     */
    public function process_subscription( $authorization, $feed, $submission_data, $form, $entry ) {
        // Update customer ID for entry.
        gform_update_meta( $entry['id'], 'HPS_customer_id', $authorization['subscription']['customer_id'] );
        $metadata = $this->get_hps_meta_data( $feed, $entry, $form );
        if ( ! empty( $metadata ) ) {
            // Update to user meta post entry creation so entry ID is available.
            try {
                // Get customer.
                $customer = $this->get_customer( $authorization['subscription']['customer_id'] );
                // Update customer metadata.
                $customer->metadata = $metadata;
                // Save customer.
                $customer->save();
            } catch ( \Exception $e ) {
                // Log that we could not save customer.
                $this->log_error( __METHOD__ . '(): Unable to save customer; ' . $e->getMessage() );
            }
        }
        return parent::process_subscription( $authorization, $feed, $submission_data, $form, $entry );
    }
    /**
     * Generate the subscription plan id.
     *
     * @since  Unknown
     * @access public
     *
     * @used-by GFSecureSubmit::subscribe()
     *
     * @param array     $feed The feed object currently being processed.
     * @param float|int $payment_amount The recurring amount.
     * @param int       $trial_period_days The number of days the trial should last.
     *
     * @return string The subscription plan ID, if found.
     */
    public function get_subscription_plan_id( $feed, $payment_amount, $trial_period_days ) {

        $safe_trial_period = $trial_period_days ? 'trial' . $trial_period_days . 'days' : '';

        $safe_feed_name     = str_replace( ' ', '', strtolower( $feed['meta']['feedName'] ) );
        $safe_billing_cycle = $feed['meta']['billingCycle_length'] . $feed['meta']['billingCycle_unit'];

        $plan_id = implode( '_', array_filter( array( $safe_feed_name, $feed['id'], $safe_billing_cycle, $safe_trial_period, $payment_amount ) ) );

        return $plan_id;

    }
    /**
 * Subscribe the user to a HPS Pay plan. This process works like so:
 *
 * 1 - Get existing plan or create new plan (plan ID generated by feed name, id and recurring amount).
 * 2 - Create new customer.
 * 3 - Create new subscription by subscribing customer to plan.
 *
 * @since  Unknown
 * @access public
 *
 * @uses GFSecureSubmit::includeSecureSubmitSDK()
 * @uses GFSecureSubmit::getSecureSubmitJsError()
 * @uses GFPaymentAddOn::authorization_error()
 * @uses GFSecureSubmit::get_subscription_plan_id()
 * @uses GFSecureSubmit::get_plan()
 * @uses GFSecureSubmit::getSecureSubmitJsResponse()
 * @uses GFSecureSubmit::create_plan()
 * @uses GFSecureSubmit::get_customer()
 * @uses GFAddOn::log_debug()
 * @uses GFPaymentAddOn::get_amount_export()
 * @uses GFAddOn::get_field_value()
 * @uses GFSecureSubmit::get_hps_meta_data()
 * @uses GFAddOn::maybe_override_field_value()
 * @uses GFSecureSubmit::create_customer()
 * @uses \Stripe\Customer::save()
 * @uses \Stripe\Customer::updateSubscription()
 * @uses \Stripe\Customer::addInvoiceItem()
 *
 * @param array $feed            The feed object currently being processed.
 * @param array $submission_data The customer and transaction data.
 * @param array $form            The form object currently being processed.
 * @param array $entry           The entry object currently being processed.
 *
 * @return array Subscription details if successful. Contains error message if failed.
 */
    public function subscribe( $feed, $submission_data, $form, $entry ) {

        // Include HPS API library.
        $this->includeSecureSubmitSDK();

        // If there was an error when retrieving the HPS.js token, return an authorization error.
        if ( $this->getSecureSubmitJsError() ) {
            return $this->authorization_error( $this->getSecureSubmitJsError() );
        }

        // Prepare payment amount and trial period data.
        $payment_amount        = $submission_data['payment_amount'];
        $single_payment_amount = $submission_data['setup_fee'];
        $trial_period_days     = rgars( $feed, 'meta/trialPeriod' ) ? $submission_data['trial'] : null;
        $currency              = rgar( $entry, 'currency' );

        // Get HPS plan for feed.
        $plan_id = $this->get_subscription_plan_id( $feed, $payment_amount, $trial_period_days );
        $plan    = $this->get_plan( $plan_id );

        // If error was returned when retrieving plan, return plan.
        if ( rgar( $plan, 'error_message' ) ) {
            return $plan;
        }

        try {

            // If plan does not exist, create it.
            if ( ! $plan ) {
                $plan = $this->create_plan( $plan_id, $feed, $payment_amount, $trial_period_days, $currency );
            }

            // Get HPS.js token.
            $token_response = $this->getSecureSubmitJsResponse();

            $customer = $this->get_customer( '', $feed, $entry, $form );

            if ( $customer ) {

                $this->log_debug( __METHOD__ . '(): Updating existing customer.' );

                // Update the customer source with the HPS token.
                $customer->source = $token_response->id;
                $customer->save();

                // If a setup fee is required, add an invoice item.
                if ( $single_payment_amount ) {
                    $setup_fee = array(
                        'amount'   => $this->get_amount_export( $single_payment_amount, $currency ),
                        'currency' => $currency,
                    );
                    $customer->addInvoiceItem( $setup_fee );
                }

                // Add subscription to customer.
                $subscription = $customer->updateSubscription( array( 'plan' => $plan->id ) );

                // Define subscription ID.
                $subscription_id = $subscription->id;

            } else {

                // Prepare customer metadata.
                $customer_meta = array(
                    'description'     => $this->get_field_value( $form, $entry, rgar( $feed['meta'], 'customerInformation_description' ) ),
                    'email'           => $this->get_field_value( $form, $entry, rgar( $feed['meta'], 'customerInformation_email' ) ),
                    'source'          => $token_response->id,
                    'account_balance' => $this->get_amount_export( $single_payment_amount, $currency ),
                    'metadata'        => $this->get_hps_meta_data( $feed, $entry, $form ),
                );

                // Get coupon for feed.
                $coupon_field_id = rgar( $feed['meta'], 'customerInformation_coupon' );
                $coupon          = $this->maybe_override_field_value( rgar( $entry, $coupon_field_id ), $form, $entry, $coupon_field_id );

                // If coupon is set, add it to customer metadata.
                if ( $coupon ) {
                    $customer_meta['coupon'] = $coupon;
                }

                $has_filter = has_filter( 'gform_stripe_customer_after_create' );

                if ( ! $has_filter ) {
                    // If filter is not being used add the plan to customer metadata; resolves a currency issue.
                    $customer_meta['plan'] = $plan;
                }

                $customer = $this->create_customer( $customer_meta, $feed, $entry, $form );

                if ( $has_filter ) {
                    // Add subscription to customer.
                    $subscription = $customer->updateSubscription( array( 'plan' => $plan->id ) );

                    // Define subscription ID.
                    $subscription_id = $subscription->id;
                } else {
                    // Define subscription ID.
                    $subscription_id = $customer->subscriptions->data[0]->id;
                }

            }

        } catch ( \Exception $e ) {

            // Return authorization error.
            return $this->authorization_error( $e->getMessage() );

        }

        // Return subscription data.
        return array(
            'is_success'      => true,
            'subscription_id' => $subscription_id,
            'customer_id'     => $customer->id,
            'amount'          => $payment_amount,
        );

    }
    // # HPS HELPER FUNCTIONS ---------------------------------------------------------------------------------------

    /**
     * Retrieve a specific customer from HPS.
     *
     * @since  Unknown
     * @access public
     *
     * @used-by GFSecureSubmit::authorize_product()
     * @used-by GFSecureSubmit::cancel()
     * @used-by GFSecureSubmit::process_subscription()
     * @used-by GFSecureSubmit::subscribe()
     * @uses    GFAddOn::log_debug()
     * @uses    \Stripe\Customer::retrieve()
     *
     * @param string $customer_id The identifier of the customer to be retrieved.
     * @param array  $feed        The feed currently being processed.
     * @param array  $entry       The entry currently being processed.
     * @param array  $form        The which created the current entry.
     *
     * @return bool|\Stripe\Customer Contains customer data if available. Otherwise, false.
     */
    public function get_customer( $customer_id, $feed = array(), $entry = array(), $form = array() ) {
        if ( empty( $customer_id ) && has_filter( 'gform_stripe_customer_id' ) ) {
            $this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_stripe_customer_id.' );

            /**
             * Allow an existing customer ID to be specified for use when processing the submission.
             *
             * @since  2.1.0
             * @access public
             *
             * @param string $customer_id The identifier of the customer to be retrieved. Default is empty string.
             * @param array  $feed        The feed currently being processed.
             * @param array  $entry       The entry currently being processed.
             * @param array  $form        The form which created the current entry.
             */
            $customer_id = apply_filters( 'gform_stripe_customer_id', $customer_id, $feed, $entry, $form );
        }

        if ( $customer_id ) {
            $this->log_debug( __METHOD__ . '(): Retrieving customer id => ' . print_r( $customer_id, 1 ) );
            $customer = \Stripe\Customer::retrieve( $customer_id );

            return $customer;
        }

        return false;
    }

    /**
     * Create and return a HPS customer with the specified properties.
     *
     * @since  Unknown
     * @access public
     *
     * @used-by GFSecureSubmit::subscribe()
     * @uses    GFAddOn::log_debug()
     * @uses    \Stripe\Customer::create()
     *
     * @param array $customer_meta The customer properties.
     * @param array $feed          The feed currently being processed.
     * @param array $entry         The entry currently being processed.
     * @param array $form          The form which created the current entry.
     *
     * @return \Stripe\Customer The HPS customer object.
     */
    public function create_customer( $customer_meta, $feed, $entry, $form ) {

        // Log the customer to be created.
        $this->log_debug( __METHOD__ . '(): Customer meta to be created => ' . print_r( $customer_meta, 1 ) );

        // Create customer.
        $customer = \Stripe\Customer::create( $customer_meta );

        if ( has_filter( 'gform_stripe_customer_after_create' ) ) {
            // Log that filter will be executed.
            $this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_stripe_customer_after_create.' );

            /**
             * Allow custom actions to be performed between the customer being created and subscribed to the plan.
             *
             * @since 2.0.1
             *
             * @param Stripe\Customer $customer The Stripe customer object.
             * @param array           $feed     The feed currently being processed.
             * @param array           $entry    The entry currently being processed.
             * @param array           $form     The form currently being processed.
             */
            do_action( 'gform_stripe_customer_after_create', $customer, $feed, $entry, $form );
        }

        return $customer;
    }

    /**
     * Try and retrieve the plan if a plan with the matching id has previously been created.
     *
     * @since  Unknown
     * @access public
     *
     * @used-by GFSecureSubmit::subscribe()
     * @uses    \Stripe\Plan::retrieve()
     * @uses    GFPaymentAddOn::authorization_error()
     *
     * @param string $plan_id The subscription plan id.
     *
     * @return array|bool|string $plan The plan details. False if not found. If invalid request, the error message.
     */
    public function get_plan( $plan_id ) {

        try {

            // Get HPS plan.
            $plan = \Stripe\Plan::retrieve( $plan_id );

        } catch ( \Exception $e ) {

            /**
             * There is no error type specific to failing to retrieve a subscription when an invalid plan ID is passed. We assume here
             * that any 'invalid_request_error' means that the subscription does not exist even though other errors (like providing
             * incorrect API keys) will also generate the 'invalid_request_error'. There is no way to differentiate these requests
             * without relying on the error message which is more likely to change and not reliable.
             */

            // Get error response.
            //TODO: replace this calll
            //$response = $e->getJsonBody();

            // If error is an invalid request error, return error message.
            if ( rgars( $response, 'error/type' ) !== 'invalid_request_error' ) {
                $plan = $this->authorization_error( $e->getMessage() );
            } else {
                $plan = false;
            }

        }

        return $plan;
    }

    /**
     * Create and return a HPS plan with the specified properties.
     *
     * @since  Unknwon
     * @access public
     *
     * @used-by GFSecureSubmit::subscribe()
     * @uses    GFPaymentAddOn::get_amount_export()
     * @uses    \Stripe\Plan::create()
     * @uses    GFAddOn::log_debug()
     *
     * @param string    $plan_id           The plan ID.
     * @param array     $feed              The feed currently being processed.
     * @param float|int $payment_amount    The recurring amount.
     * @param int       $trial_period_days The number of days the trial should last.
     * @param string    $currency          The currency code for the entry being processed.
     *
     * @return \Stripe\Plan The plan object.
     */
    public function create_plan( $plan_id, $feed, $payment_amount, $trial_period_days, $currency ) {
        // Prepare plan metadata.
        $plan_meta = array(
            'interval'          => $feed['meta']['billingCycle_unit'],
            'interval_count'    => $feed['meta']['billingCycle_length'],
            'name'              => $feed['meta']['feedName'],
            'currency'          => $currency,
            'id'                => $plan_id,
            'amount'            => $this->get_amount_export( $payment_amount, $currency ),
            'trial_period_days' => $trial_period_days,
        );

        // Log the plan to be created.
        $this->log_debug( __METHOD__ . '(): Plan to be created => ' . print_r( $plan_meta, 1 ) );

        // Create HPS plan.
        $plan = \Stripe\Plan::create( $plan_meta );

        return $plan;
    }



}
