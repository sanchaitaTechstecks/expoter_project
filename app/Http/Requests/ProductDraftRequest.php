<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductDraftRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $rules = [];
        $rules['name'] = 'nullable|max:255';

        $rules['category_ids'] = 'nullable|array';

        if ($this->filled('category_id') && $this->filled('category_ids')) {
            $rules['category_id'] = ['in:' . implode(',', $this->category_ids)];
        }
        $rules['unit']           = 'nullable|string|max:50';
        $rules['min_qty']        = 'nullable|numeric';
        $rules['unit_price']     = 'nullable|numeric';
        $rules['current_stock']  = 'nullable|numeric';
        $rules['starting_bid']   = 'nullable|numeric|min:1';
        $rules['auction_date_range'] = 'nullable|string';

        $rules['prescription_required'] = 'nullable|boolean';
        $rules['use_by_date']           = 'nullable|date|date_format:Y-m-d';

        if ($this->filled('discount')) {
            if ($this->get('discount_type') == 'amount') {
                $rules['discount'] = 'numeric|lte:' . ($this->unit_price ?? 999999);
            } else {
                $rules['discount'] = 'numeric|lte:100';
            }
        }


        return $rules;
    }

    public function messages()
    {
        return [
            'name.max'                => translate('Product name cannot exceed 255 characters'),
            'category_id.in'          => translate('Main Category must be within selected categories'),
            'min_qty.numeric'         => translate('Minimum purchase must be numeric'),
            'unit_price.numeric'      => translate('Unit price must be numeric'),
            'discount.numeric'        => translate('Discount must be numeric'),
            'discount.lte'            => translate('Discount must be less than or equal to unit price or 100%'),
            'current_stock.numeric'   => translate('Current stock must be numeric'),
            'starting_bid.numeric'    => translate('Starting Bid must be numeric'),
            'starting_bid.min'        => translate('Minimum Starting Bid is 1'),
        ];
    }
}
