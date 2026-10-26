<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttributeRequest;
use App\Http\Requests\ColorRequest;
use Illuminate\Http\Request;
use App\Models\Attribute;
use App\Models\Color;
use App\Models\AttributeTranslation;
use App\Models\AttributeValue;
use CoreComponentRepository;
use Str;

class AttributeController extends Controller
{
    public function __construct() {
        // Staff Permission Check
        $this->middleware(['permission:view_product_attributes'])->only('index');
        $this->middleware(['permission:edit_product_attribute'])->only('edit');
        $this->middleware(['permission:delete_product_attribute'])->only('destroy');

        $this->middleware(['permission:view_product_attribute_values'])->only('show');
        $this->middleware(['permission:edit_product_attribute_value'])->only('edit_attribute_value');
        $this->middleware(['permission:delete_product_attribute_value'])->only('destroy_attribute_value');

        $this->middleware(['permission:view_colors'])->only('colors');
        $this->middleware(['permission:add_color'])->only('colors_create');
        $this->middleware(['permission:edit_color'])->only('edit_color');
        $this->middleware(['permission:delete_color'])->only('destroy_color');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
        $attributes = Attribute::with('attribute_values')->orderBy('created_at', 'desc')->paginate(15);
        return view('backend.product.attribute.index', compact('attributes'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
         return view('backend.product.attribute.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(AttributeRequest $request)
    {
        $attribute = new Attribute;
        $attribute->name = $request->name;
        $attribute->save();

        $attribute_translation = AttributeTranslation::firstOrNew(['lang' => env('DEFAULT_LANGUAGE'), 'attribute_id' => $attribute->id]);
        $attribute_translation->name = $request->name;
        $attribute_translation->save();

        // Save each attribute value
        if ($request->has('attribute_values')) {
            foreach ($request->attribute_values as $value) {
                if (!empty($value)) {
                    $attribute_value = new AttributeValue;
                    $attribute_value->attribute_id = $attribute->id;
                    $attribute_value->value = ucfirst($value);
                    $attribute_value->save();
                }
            }
        }

        return response()->json([
                'success' => true,
                'message' => translate('Attribute has been inserted successfully'),
                'redirect' => route('attributes.index')
            ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $data['attribute'] = Attribute::findOrFail($id);
        $data['all_attribute_values'] = AttributeValue::with('attribute')->where('attribute_id', $id)->get();

        // echo '<pre>';print_r($data['all_attribute_values']);die;

        return view("backend.product.attribute.attribute_value.index", $data);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, $id)
    {
        $lang      = $request->lang;
        $attribute = Attribute::with('attribute_values')->findOrFail($id);
        return view('backend.product.attribute.edit', compact('attribute','lang'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(AttributeRequest $request, $id)
    {
        $attribute = Attribute::findOrFail($id);
        if($request->lang == env("DEFAULT_LANGUAGE")){
          $attribute->name = $request->name;
        }
        $attribute->save();

        $attribute_translation = AttributeTranslation::firstOrNew(['lang' => $request->lang, 'attribute_id' => $attribute->id]);
        $attribute_translation->name = $request->name;
        $attribute_translation->save();

        $this->updateAttributeValues($attribute->id, $request->attribute_values ?? [], $request->attribute_value_ids ?? []);

        return response()->json([
                'success' => true,
                'message' => translate('Attribute has been updated successfully'),
                'redirect' => route('attributes.index')
            ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $attribute = Attribute::findOrFail($id);

        foreach ($attribute->attribute_translations as $key => $attribute_translation) {
            $attribute_translation->delete();
        }

        Attribute::destroy($id);
        AttributeValue::where('attribute_id', $id)->delete();
        flash(translate('Attribute has been deleted successfully'))->success();
        return redirect()->route('attributes.index');

    }

    private function updateAttributeValues($attribute_id, $values, $ids)
    {
        $existing_ids = AttributeValue::where('attribute_id', $attribute_id)->pluck('id')->toArray();

        foreach ($values as $index => $value) {
            $id = $ids[$index] ?? null;
            $value = trim($value);
            if (empty($value)) continue;

            if ($id) {
                $attribute_value = AttributeValue::findOrFail($id);
                $attribute_value->attribute_id = $attribute_id;
                $attribute_value->value = ucfirst($value);
                $attribute_value->save();
            } else {
                $attribute_value = new AttributeValue();
                $attribute_value->attribute_id = $attribute_id;
                $attribute_value->value = ucfirst($value);
                $attribute_value->save();
            }
        }

        // Delete removed values
        $ids_to_delete = array_diff($existing_ids, array_filter($ids));
        if (!empty($ids_to_delete)) {
            AttributeValue::whereIn('id', $ids_to_delete)->delete();
        }
    }
    
    public function colors(Request $request) {
        $sort_search = null;
        $colors = Color::orderBy('created_at', 'desc');

        if ($request->search != null){
            $colors = $colors->where('name', 'like', '%'.$request->search.'%');
            $sort_search = $request->search;
        }
        $colors = $colors->paginate(10);

        return view('backend.product.color.index', compact('colors', 'sort_search'));
    }

    public function colors_create() {
        return view('backend.product.color.create');
    }
    
    public function store_color(ColorRequest $request) {
        
        $color = new Color;
        $color->name = Str::replace(' ', '', $request->name);
        $color->code = $request->code;
        
        $color->save();
        return response()->json([
                'success' => true,
                'message' => translate('Color has been inserted successfully'),
                'redirect' => route('colors')
            ]);
    }
    
    public function edit_color(Request $request, $id)
    {
        $color = Color::findOrFail($id);
        return view('backend.product.color.edit', compact('color'));
    }

    /**
     * Update the color.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update_color(ColorRequest $request, $id)
    {
        $color = Color::findOrFail($id);
        $color->name = Str::replace(' ', '', $request->name);
        $color->code = $request->code;
        
        $color->save();
        return response()->json([
                'success' => true,
                'message' => translate('Color has been updated successfully'),
                'redirect' => route('colors')
            ]);
    }
    
    public function destroy_color($id)
    {
        Color::destroy($id);
        
        flash(translate('Color has been deleted successfully'))->success();
        return redirect()->route('colors');

    }
    
}
