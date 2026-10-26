<?php

namespace App\Http\Controllers;

use App\Models\ElementStyle;
use Illuminate\Http\Request;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Country;
use App\Models\ElementType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ShippingSystem;
use App\Models\State;
use App\Models\Zone;
use Artisan;
use CoreComponentRepository;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Str;
use DB;
use ZipArchive;

class BusinessSettingsController extends Controller
{
    public function __construct()
    {
        // Staff Permission Check
        $this->middleware(['permission:seller_commission_configuration'])->only('vendor_commission');
        $this->middleware(['permission:seller_verification_form_configuration'])->only('seller_verification_form');
        $this->middleware(['permission:general_settings'])->only('general_setting');
        $this->middleware(['permission:features_activation'])->only('activation');
        $this->middleware(['permission:smtp_settings'])->only('smtp_settings');
        $this->middleware(['permission:payment_methods_configurations'])->only('payment_method');
        $this->middleware(['permission:order_configuration'])->only('order_configuration');
        $this->middleware(['permission:file_system_&_cache_configuration'])->only('file_system');
        $this->middleware(['permission:social_media_logins'])->only('social_login');
        $this->middleware(['permission:whatsapp_chat'])->only('whatsappChat');
        $this->middleware(['permission:facebook_comment'])->only('facebook_comment');
        $this->middleware(['permission:analytics_tools_configuration'])->only('google_analytics');
        $this->middleware(['permission:google_recaptcha_configuration'])->only('google_recaptcha');
        $this->middleware(['permission:google_map_setting'])->only('google_map');
        $this->middleware(['permission:google_firebase_setting'])->only('google_firebase');
        $this->middleware(['permission:shipping_configuration'])->only('shipping_configuration');
        $this->middleware(['permission:business_settings'])->only('business_settings');
    }

    public function general_setting(Request $request)
    {
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.general_settings');
    }

    public function activation(Request $request)
    {
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.activation');
    }

    public function social_login(Request $request)
    {
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.social_login');
    }

    public function smtp_settings(Request $request)
    {
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.smtp_settings');
    }

    public function google_analytics(Request $request)
    {
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.google_configuration.google_analytics');
    }

    public function google_recaptcha(Request $request)
    {
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.google_configuration.google_recaptcha');
    }

    public function google_map(Request $request)
    {
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.google_configuration.google_map');
    }

    public function google_firebase(Request $request)
    {
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.google_configuration.google_firebase');
    }

    public function whatsappChat(Request $request)
    {
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.whatsapp_chat');
    }

    public function facebook_comment(Request $request)
    {
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.facebook_configuration.facebook_comment');
    }

    public function payment_method(Request $request)
    {
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
        $payment_methods = PaymentMethod::whereNull('addon_identifier')->get();
        return view('backend.setup_configurations.payment_method.index', compact('payment_methods'));
    }

    public function file_system(Request $request)
    {
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
        return view('backend.setup_configurations.file_system');
    }

    /**
     * Update the API key's for payment methods.
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function payment_method_update(Request $request)
    {
        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', $request->payment_method . '_sandbox')->first();
        if ($business_settings != null) {
            if ($request->has($request->payment_method . '_sandbox')) {
                $business_settings->value = 1;
                $business_settings->save();
            } else {
                $business_settings->value = 0;
                $business_settings->save();
            }
        }else{
            $business_settings = new BusinessSetting();
            if ($request->has($request->payment_method . '_sandbox')) {
                $business_settings->type = $request->payment_method . '_sandbox';
                $business_settings->value = 1;
                $business_settings->save();
            } else {
                $business_settings->type = $request->payment_method . '_sandbox';
                $business_settings->value = 0;
                $business_settings->save();
            }
        }

        // Save phonepe_version to settings
        if ($request->has('phonepe_version')) {
            $phonepeVersion = BusinessSetting::where('type', 'phonepe_version')->first();
            if ($phonepeVersion) {
                $phonepeVersion->value = $request->phonepe_version;
                $phonepeVersion->save();
            } else {
                $newSetting = new BusinessSetting();
                $newSetting->type = 'phonepe_version';
                $newSetting->value = $request->phonepe_version;
                $newSetting->save();
            }
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    /**
     * Update the API key's for GOOGLE analytics.
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function google_analytics_update(Request $request)
    {
        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', 'google_analytics')->first();

        if ($request->has('google_analytics')) {
            $business_settings->value = 1;
            $business_settings->save();
        } else {
            $business_settings->value = 0;
            $business_settings->save();
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    public function google_recaptcha_update(Request $request)
    {
        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', 'google_recaptcha')->first();

        if ($request->has('google_recaptcha')) {
            $business_settings->value = 1;
            $business_settings->save();
        } else {
            $business_settings->value = 0;
            $business_settings->save();
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    public function google_map_update(Request $request)
    {
        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', 'google_map')->first();

        if ($request->has('google_map')) {
            $business_settings->value = 1;
            $business_settings->save();
        } else {
            $business_settings->value = 0;
            $business_settings->save();
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    public function google_firebase_update(Request $request)
    {
        $business_settings = BusinessSetting::where('type', 'google_firebase')->first();

        if ($request->has('google_firebase')) {
            $business_settings->value = 1;
            $business_settings->save();
        } else {
            $business_settings->value = 0;
            $business_settings->save();
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }


    /**
     * Update the API key's for GOOGLE analytics.
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function whatsappChatUpdate(Request $request)
    {
        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }
        $settings = [
            'whatsapp_chat' => BusinessSetting::where('type', 'whatsapp_chat')->first(),
            'whatsapp_order' => BusinessSetting::where('type', 'whatsapp_order')->first(),
            'whatsapp_order_seller_prods' => BusinessSetting::where('type', 'whatsapp_order_seller_prods')->first(),
            'order_messege_template' => BusinessSetting::where('type', 'order_messege_template')->first()
        ];

        foreach ($settings as $key => $setting) {
            if ($setting) {
                $setting->value = $request->has($key) ? $request->input($key) : 0;
                $setting->save();
            }
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    public function facebook_comment_update(Request $request)
    {
        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', 'facebook_comment')->first();
        if (!$business_settings) {
            $business_settings = new BusinessSetting;
            $business_settings->type = 'facebook_comment';
        }

        $business_settings->value = 0;
        if ($request->facebook_comment) {
            $business_settings->value = 1;
        }

        $business_settings->save();

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    public function facebook_pixel_update(Request $request)
    {
        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        $business_settings = BusinessSetting::where('type', 'facebook_pixel')->first();

        if ($request->has('facebook_pixel')) {
            $business_settings->value = 1;
            $business_settings->save();
        } else {
            $business_settings->value = 0;
            $business_settings->save();
        }

        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    /**
     * Update the API key's for other methods.
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function env_key_update(Request $request)
    {
        foreach ($request->types as $key => $type) {
            $this->overWriteEnvFile($type, $request[$type]);
        }

        flash(translate("Settings updated successfully"))->success();
        return back();
    }

    /**
     * overWrite the Env File values.
     * @param  String type
     * @param  String value
     * @return \Illuminate\Http\Response
     */
    public function overWriteEnvFile($type, $val)
    {
        if (env('DEMO_MODE') != 'On') {
            $path = base_path('.env');
            if (file_exists($path)) {
                $val = '"' . trim($val) . '"';
                if (is_numeric(strpos(file_get_contents($path), $type)) && strpos(file_get_contents($path), $type) >= 0) {
                    file_put_contents($path, str_replace(
                        $type . '="' . env($type) . '"',
                        $type . '=' . $val,
                        file_get_contents($path)
                    ));
                } else {
                    file_put_contents($path, file_get_contents($path) . "\r\n" . $type . '=' . $val);
                }
            }
        }
    }

    public function seller_verification_form(Request $request)
    {
        return view('backend.sellers.seller_verification_form.index');
    }

    /**
     * Update sell verification form.
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function seller_verification_form_update(Request $request)
    {
        $form = array();
        $select_types = ['select', 'multi_select', 'radio'];
        $j = 0;
        for ($i = 0; $i < count($request->type); $i++) {
            $item['type'] = $request->type[$i];
            $item['label'] = $request->label[$i];
            if (in_array($request->type[$i], $select_types)) {
                $item['options'] = json_encode($request['options_' . $request->option[$j]]);
                $j++;
            }
            array_push($form, $item);
        }
        $business_settings = BusinessSetting::where('type', 'verification_form')->first();
        $business_settings->value = json_encode($form);
        if ($business_settings->save()) {
            Artisan::call('cache:clear');

            flash(translate("Verification form updated successfully"))->success();
            return back();
        }
    }

    public function update(Request $request)
    {
       // dd($request->all());
        $types = $request->types ?? [];
        $resetRefundData = in_array('refund_type', $types);

        foreach ($request->types as $key => $type) {
            if ($type == 'site_name') {
                $this->overWriteEnvFile('APP_NAME', $request[$type]);
            }
            if ($type == 'timezone') {
                $this->overWriteEnvFile('APP_TIMEZONE', $request[$type]);
            } else {
                $lang = null;
                if (gettype($type) == 'array') {
                    $lang = array_key_first($type);
                    $type = $type[$lang];
                    $business_settings = BusinessSetting::where('type', $type)->where('lang', $lang)->first();
                } else {
                    $business_settings = BusinessSetting::where('type', $type)->first();
                }

                if ($business_settings != null) {
                    if (gettype($request[$type]) == 'array') {
                        $business_settings->value = json_encode($request[$type]);
                    } else {
                        $business_settings->value = $request[$type];
                        if ($type == "seller_commission_type"  && $request[$type] == "category_based") {
                            $business_settings2 = BusinessSetting::where('type', 'category_wise_commission')->first();
                            $business_settings2->value = 1;
                            $business_settings2->save();
                        } elseif ($type == "seller_commission_type" && ($request[$type] == "seller_based" || $request[$type] == "fixed_rate")) {
                            $business_settings2 = BusinessSetting::where('type', 'category_wise_commission')->first();
                            $business_settings2->value = 0;
                            $business_settings2->save();
                        }
                    }
                    $business_settings->lang = $lang;
                    $business_settings->save();
                } else {
                    $business_settings = new BusinessSetting;
                    $business_settings->type = $type;
                    if (gettype($request[$type]) == 'array') {
                        $business_settings->value = json_encode($request[$type]);
                    } else {
                        $business_settings->value = $request[$type];
                    }
                    $business_settings->lang = $lang;
                    $business_settings->save();
                }
            }
        }

        
        if ($resetRefundData) {
            Product::query()->update([
                'refundable' => 0,
            ]);
            Category::query()->update([
                'refund_request_time' => null,
            ]);
            BusinessSetting::where('type', 'refund_request_time')->update([
                'value' => null,
            ]);
        }
        Artisan::call('cache:clear');

        flash(translate("Settings updated successfully"))->success();
        // If the request from a tabs with tab input
        if ($request->has('tab')) {
            return Redirect::to(URL::previous() . "#" . $request->tab);
        }
        return redirect()->back();
    }


    public function updateActivationSettings(Request $request)
    {
        $env_changes = ['FORCE_HTTPS', 'FILESYSTEM_DRIVER'];
        if (in_array($request->type, $env_changes)) {

            return $this->updateActivationSettingsInEnv($request);
        }

        $business_settings = BusinessSetting::where('type', $request->type)->first();
        if ($business_settings != null) {
            if ($request->type == 'maintenance_mode' && $request->value == '1') {
                if (env('DEMO_MODE') != 'On') {
                    Artisan::call('down');
                }
            } elseif ($request->type == 'maintenance_mode' && $request->value == '0') {
                if (env('DEMO_MODE') != 'On') {
                    Artisan::call('up');
                }
            }
            $business_settings->value = $request->value;
            $business_settings->save();
        } else {
            $business_settings = new BusinessSetting;
            $business_settings->type = $request->type;
            $business_settings->value = $request->value;
            $business_settings->save();
        }

        Artisan::call('cache:clear');
        return 1;
    }

    public function updatePaymentActivationSettings(Request $request)
    {
        $payment_method = PaymentMethod::findOrFail($request->id);
        $payment_method->active = $request->value;
        $payment_method->save();

        Artisan::call('cache:clear');
        return 1;
    }

    public function updateShippingActivationSettings(Request $request)
    {
        $shipping_system = ShippingSystem::findOrFail($request->id);
        $shipping_system->active = $request->value;
        $shipping_system->save();

        Artisan::call('cache:clear');
        return 1;
    }

    public function updateActivationSettingsInEnv($request)
    {
        if ($request->type == 'FORCE_HTTPS' && $request->value == '1') {
            $this->overWriteEnvFile($request->type, 'On');

            if (strpos(env('APP_URL'), 'http:') !== FALSE) {
                $this->overWriteEnvFile('APP_URL', str_replace("http:", "https:", env('APP_URL')));
            }
        } elseif ($request->type == 'FORCE_HTTPS' && $request->value == '0') {
            $this->overWriteEnvFile($request->type, 'Off');
            if (strpos(env('APP_URL'), 'https:') !== FALSE) {
                $this->overWriteEnvFile('APP_URL', str_replace("https:", "http:", env('APP_URL')));
            }
        } elseif ($request->type == 'FILESYSTEM_DRIVER') {
            $this->overWriteEnvFile($request->type, $request->value);
        }

        return 1;
    }

    public function vendor_commission(Request $request)
    {
        return view('backend.sellers.seller_commission.index');
    }

    public function shipping_configuration(Request $request)
    {
        $countries = Country::where('status', 1)->get();
        return view('backend.setup_configurations.shipping_configuration.index', compact('countries'));
    }

    public function shipping_method(Request $request)
    {
        $countries = Country::where('status', 1)->get();
        return view('backend.setup_configurations.shipping_configuration.shipping_method', compact('countries'));
    }



    public function shipping_configuration_update(Request $request)
    {
        if ($request->type == 'shipping_type' && $request->shipping_type == 'carrier_wise_shipping') {
            $inactiveZoneIds = Zone::where('status', 0)->pluck('id')->toArray();
            $hasInvalidCountries = Country::where('status', 1)
                ->where(function ($query) use ($inactiveZoneIds) {
                    $query->where('zone_id', 0)
                        ->orWhereIn('zone_id', $inactiveZoneIds);
                })
                ->exists();

            if ($hasInvalidCountries) {
                flash(translate('Your active shipping countries are assigned to inactive or undefined shipping zones. Please review your zone setup before enabling carrier-wise shipping.'))->error();
                return back();
            }
        }
        $business_settings = BusinessSetting::where('type', $request->type)->first();
        $business_settings->value = $request[$request->type];

        $business_settings->save();

        Artisan::call('cache:clear');
        flash(translate('Shipping Method updated successfully'))->success();
        return back();
    }

    public function order_configuration()
    {
        return view('backend.setup_configurations.order_configuration.index');
    }

    // public function import_data(Request $request)
    // {
    //     if (env("DEMO_MODE") == "On"){
    //         flash(translate('Demo data import will not work in demo site'))->error();
    //         return back();
    //     }
    //     $url = 'https://demo.activeitzone.com/envato/ecommerce-demo-data-import/import';
    //     $header = array(
    //         'Content-Type:application/json'
    //     );
    //     $data['main_url'] = $request->main_url;
    //     $data['domain'] = $request->domain;
    //     $data['purchase_key'] = $request->purchase_key;
    //     $data['layout'] = $request->layout;
    //     $request_data_json = json_encode($data);

    //     $ch = curl_init();
    //     curl_setopt($ch, CURLOPT_URL, $url);
    //     curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    //     curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //     curl_setopt($ch, CURLOPT_POSTFIELDS, $request_data_json);
    //     curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    //     curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    //     $raw_file_data = curl_exec($ch);

    //     if(json_decode($raw_file_data, true)['status']) {
    //         flash(translate('Demo data uploaded successfully'))->success();
    //     } else {
    //         flash(translate(json_decode($raw_file_data, true)['message']))->error();
    //     }

    //     return back();
    // }


    public function import_data(Request $request)
    {
        if (env("DEMO_MODE") == "On") {
            flash(translate('Demo data import will not work in demo site'))->error();
            return back();
        }

        if (! AddonController::isLocalhostDomain()) {

            $check_domain_verification =  AddonController::checkVerification('item', $request->purchase_key);
            $check_domain_activation =  AddonController::checkActivation('item', $request->purchase_key);

            if (!$check_domain_verification || !$check_domain_activation) {
                return translate('Please activate your domain at first');
            }
        }

        // import sql
        $sql_path = base_path('public/demo.sql');
        DB::unprepared(file_get_contents($sql_path));

        // extract images
        $zip = new ZipArchive;
        $zip->open(base_path('public/uploads.zip'));
        $zip->extractTo('public/uploads/all/');
        flash(translate('Demo data uploaded successfully'))->success();
        return redirect()->back();
    }

    public function stateBasedShippingSettings(Request $request)
    {
        $business_settings = BusinessSetting::where('type', 'has_state')->first();
        if (!$business_settings) {
            $business_settings = new BusinessSetting();
            $business_settings->type = 'has_state';
        }

        $business_settings->value = $request->has_state;

        $business_settings->save();

        Artisan::call('cache:clear');
            return $request->has_state ? 1 : 0;
       
    }

    public function select_header(Request $request)
    {
        $business_settings = BusinessSetting::where('type', 'header_element')->first();
        if (!$business_settings) {
            $business_settings = new BusinessSetting();
            $business_settings->type = 'header_element';
        }

        $business_settings->value = $request->header_element;
        $business_settings->save();
        $selectedElementType = ElementType::find($request->header_element);
        foreach ($selectedElementType->element_styles as $style) {
            $businessSetting = BusinessSetting::where('type', $style->name)->first();
            if (!$businessSetting) {
                $businessSetting = new BusinessSetting();
                $businessSetting->type = $style->name;
                $businessSetting->value = $style->value;
                $businessSetting->save();
            }else{
                $businessSetting->value = $style->value;
                $businessSetting->save();
            }
        }
        Artisan::call('cache:clear');
        flash(translate('Header layout updated successfully'))->success();
        return redirect()->back();
    }


    public function customProductVisitorsUpdate( Request $request)
    {
         $settings = [
            'show_custom_product_visitors' => BusinessSetting::where('type', 'show_custom_product_visitors')->first(),
            'min_custom_product_visitors' => BusinessSetting::where('type', 'min_custom_product_visitors')->first(),
            'max_custom_product_visitors' => BusinessSetting::where('type', 'max_custom_product_visitors')->first()
        ];

        foreach ($settings as $key => $setting) {
            if ($setting) {
                $setting->value = $request->has($key) ? $request->input($key) : 0;
                $setting->save();
            }else{
                $newSetting = new BusinessSetting();
                $newSetting->type = $key;
                $newSetting->value = $request->has($key) ? $request->input($key) : 0;
                $newSetting->save();
            }
        }

        Artisan::call('cache:clear');
        flash(translate("Custom Product Visitors settings updated successfully"))->success();
        return back();
    }

    public function select_font_family(Request $request)
    {
        $fonts = config('font') ?? [];
        if (!is_array($fonts)) {
            $fonts = array_values((array) $fonts);
        } else {
            $fonts = array_values($fonts);
        }
        $selectedFont = get_setting('system_font_family') ?? '';
        return view('backend.font_family', compact('fonts', 'selectedFont'));
    }

    public function business_settings(){
        $business_info = json_decode(get_setting('business_info'), true) ?? [];
        return view('backend.setup_configurations.business_settings', compact('business_info'));
    }

    public function business_info_update(Request $request)
    {
        $business_settings = BusinessSetting::where('type', 'business_info')->first();
        
        $business_info = $business_settings 
            ? json_decode($business_settings->value, true) ?? [] 
            : [];

        if ($request->has('certificate_number')) {
            $business_info['certificate_number'] = $request->certificate_number;

            if ($request->hasFile('certificate')) {
                if (!empty($business_info['certificate']) && file_exists(public_path($business_info['certificate']))) {
                    unlink(public_path($business_info['certificate']));
                }

                $business_info['certificate'] = $request->file('certificate')->store('uploads/verification_form');
            }

            $business_info['country'] = Country::find($request->country_id)?->name ?? '';
            $business_info['state']   = State::find($request->state_id)?->name ?? '';
            $business_info['address'] = $request->address;
            $business_info['shop_logo'] = $request->shop_logo;
            $business_info['invoice_logo'] = $request->invoice_logo;
        }

        if (addon_is_activated('gst_system') && $request->has('gstin_number')) {
            $business_info['gstin'] = $request->gstin_number;

            if ($request->hasFile('gstin_certificate')) {
                if (!empty($business_info['gstin_certificate']) && file_exists(public_path($business_info['gstin_certificate']))) {
                    unlink(public_path($business_info['gstin_certificate']));
                }

                $business_info['gstin_certificate'] = $request->file('gstin_certificate')->store('uploads/verification_form');
            }
        }

        // Now: manually check if record exists → create or update
        if ($business_settings) {
            // Update existing
            $business_settings->value = json_encode($business_info);
            $business_settings->save();
        } else {
            $newSetting = new BusinessSetting();
            $newSetting->type = 'business_info';
            $newSetting->value = json_encode($business_info);
            $newSetting->save();
        }

        Artisan::call('cache:clear');

        flash(translate("Business info updated successfully"))->success();

        return back();
    }
}