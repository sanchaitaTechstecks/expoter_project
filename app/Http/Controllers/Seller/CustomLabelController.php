<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CustomLabelTranslation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Redirect;
use App\Models\CustomLabel;
use App\Models\Product;

class CustomLabelController extends Controller
{
    public function index(Request $request)
    {
        $sort_search = null;
        $custom_labels = CustomLabel::where('user_id', auth()->id())
            ->orWhere(function ($query) {
                $query->where('user_id', get_admin()->id)
                    ->where('seller_access', 1);
            });

        if ($request->has('search')) {
            $sort_search = $request->search;
            $custom_labels->where('text', 'like', '%' . $sort_search . '%');
        }
        $custom_labels = $custom_labels->orderBy('created_at', 'desc')->paginate(15);

        return view('seller.product.custom_label.custom_label_list', compact('custom_labels', 'sort_search'));
    }

    public function create()
    {
        $user_id = Auth::id();
        $products = Product::isApprovedPublished()->where('user_id', $user_id)->where('auction_product', 0)->orderBy('created_at', 'desc')->get();
        return view('seller.product.custom_label.custom_label_create', compact('products'));
    }

    public function store(Request $request)
    {
        $user_id = Auth::id();
        $validator  = Validator::make($request->all(), [
            'text'     => 'required',
            'background_color'   => 'required',
            'text_color'   => 'required',
        ]);

        if ($validator->fails()) {
            flash(translate('Sorry! Something went wrong'))->error();
            return Redirect::back()->withErrors($validator);
        }

        $custom_label = new CustomLabel();
        $custom_label->text      = $request->text;
        $custom_label->text_color      = $request->text_color;
        $custom_label->background_color   = $request->background_color;
        $custom_label->user_id           = $user_id;
        $custom_label->save();

        $custom_label_translation = CustomLabelTranslation::firstOrNew([
            'lang' => env('DEFAULT_LANGUAGE'),
            'custom_label_id' => $custom_label->id
        ]);
        $custom_label_translation->text = $request->text;
        $custom_label_translation->save();

        if ($request->has('products') && count($request->products) > 0) {
            Product::whereIn('id', $request->products)
                ->update(['custom_label_id' => $custom_label->id]);
        }

        flash(translate('Custom Label has been created successfully!'))->success();
        return redirect()->route('seller.custom_label.index');
    }

    public function products(Request $request)
    {
        $product_ids = $request->product_ids;
        return view('seller.product.custom_label.products', compact('product_ids'));
    }

    public function edit(Request $request, $id)
    {
        $user_id = Auth::id();

        $all_products = Product::isApprovedPublished()
            ->where('auction_product', 0)
            ->where('user_id', $user_id)
            ->orderBy('created_at', 'desc')
            ->get();

        $selected_products_ids = Product::where('custom_label_id', $id)
            ->where('user_id', $user_id)
            ->pluck('id')
            ->toArray();

        $selected_products = $all_products->whereIn('id', $selected_products_ids);
        $unselected_products = $all_products->whereNotIn('id', $selected_products_ids);
        $products = $selected_products->merge($unselected_products);

        $lang = $request->lang;
        $custom_label = CustomLabel::findOrFail($id);
        $is_admin_added = optional($custom_label->user)->user_type === 'admin';

        return view('seller.product.custom_label.custom_label_edit', compact('custom_label', 'lang', 'products', 'selected_products_ids', 'is_admin_added'));
    }


    public function update(Request $request, $id)
    {
        $custom_label = CustomLabel::findOrFail($id);
        if ($request->lang == env("DEFAULT_LANGUAGE")) {
            $custom_label->text     = $request->text;
        }
        $custom_label->background_color   = $request->background_color;
        $custom_label->text_color   = $request->text_color;
        $custom_label->save();

        $custom_label_translation = CustomLabelTranslation::firstOrNew(['lang' => $request->lang, 'custom_label_id' => $custom_label->id]);
        $custom_label_translation->text = $request->text;
        $custom_label_translation->save();

        $selected_product_ids = $request->has('products') ? $request->products : [];

        Product::where('custom_label_id', $custom_label->id)
            ->whereNotIn('id', $selected_product_ids)
            ->update(['custom_label_id' => null]);

        Product::whereIn('id', $selected_product_ids)
            ->update(['custom_label_id' => $custom_label->id]);

        flash(translate('Custom Label has been updated successfully!'))->success();
        return back();
    }

    public function destroy($id)
    {
        $custom_label = CustomLabel::findOrFail($id);
        $custom_label->delete();
        flash(translate('Custom Label has been deleted successfully!'))->success();
        return back();
    }
}
