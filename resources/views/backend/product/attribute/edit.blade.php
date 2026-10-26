@extends('backend.layouts.app')

@section('content')
    <div class="aiz-titlebar text-left mt-2 mb-3">
        <h5 class="mb-0 h6">{{ translate('Attribute Information') }}</h5>
    </div>

    <div class="col-lg-8 mx-auto">
        <div class="card">
            <div class="card-body p-0">
                <ul class="nav nav-tabs nav-fill language-bar">
                    @foreach (get_all_active_language() as $key => $language)
                        <li class="nav-item">
                            <a class="nav-link text-reset @if ($language->code == $lang) active @endif py-3"
                                href="{{ route('attributes.edit', ['id' => $attribute->id, 'lang' => $language->code]) }}">
                                <img src="{{ static_asset('assets/img/flags/' . $language->code . '.png') }}" height="11"
                                    class="mr-1">
                                <span>{{ $language->name }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <form class="p-4" action="{{ route('attributes.update', $attribute->id) }}" method="POST" id="aizSubmitForm">
                    <input name="_method" type="hidden" value="PATCH">
                    <input type="hidden" name="lang" value="{{ $lang }}">
                    @csrf
                    <div class="form-group mb-3">
                        <label class="col-from-label" for="name">{{ translate('Name') }} <i
                                class="las la-language text-danger" title="{{ translate('Translatable') }}"></i></label>
                        <input type="text" placeholder="{{ translate('Name') }}" id="name" name="name"
                            class="form-control" required value="{{ $attribute->getTranslation('name', $lang) }}">
                    </div>
                    <div id="attribute-wrapper">
                        <div class="form-group mb-3">
                            <label>{{ translate('Attribute Values') }}</label>
                                @forelse ($attribute->attribute_values as $value)
                                    <div class="row gutters-5 align-items-center mb-2">
                                        <div class="col">
                                            <div class="form-group">
                                                <input type="hidden" name="attribute_value_ids[]" value="{{ $value->id }}">
                                                <input type="text" class="form-control" name="attribute_values[]" maxlength="60"
                                                    value="{{ $value->value }}" placeholder="{{ translate('Enter Attribute Value') }}">
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <button type="button"
                                                class="mb-3 pt-2 btn btn-icon btn-circle btn-sm btn-soft-danger remove-row">
                                                <i class="las la-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                @empty
                                    <div class="row attribute-row gutters-5 align-items-center mb-2">
                                        <div class="col">
                                            <div class="form-group">
                                                <input type="hidden" name="attribute_value_ids[]" value="">
                                                <input type="text" class="form-control"
                                                    placeholder="{{ translate('Enter Attribute Value') }}" name="attribute_values[]" maxlength="60" value="">
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <button type="button"
                                                class="mb-3 pt-2 btn btn-icon btn-circle btn-sm btn-soft-danger remove-row">
                                                <i class="las la-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                @endforelse

                            {{-- Add New Button --}}
                            <button type="button" class="btn btn-block border border-dashed hov-bg-soft-secondary fs-14 rounded-0 d-flex align-items-center justify-content-center add-row">
                                    <i class="las la-plus"></i>
                                    <span class="ml-2">Add More</span>
                            </button>
                        </div>

                    </div>
                   


                    
                    <div class="form-group mb-0 text-right">
                        <button type="submit" class="btn btn-primary">{{ translate('Save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script>
document.addEventListener("DOMContentLoaded", function () {
    document.addEventListener("click", function (e) {
        // Add new row
        const addBtn = e.target.closest(".add-row");
        if (addBtn) {
            const wrapper = addBtn.closest("#attribute-wrapper").querySelector(".form-group");
            const newRow = document.createElement("div");
            newRow.classList.add("row", "gutters-5", "align-items-center", "mb-2", "attribute-row");

            newRow.innerHTML = `
                <div class="col">
                    <div class="form-group">
                        <input type="hidden" name="attribute_value_ids[]" value="">
                        <input type="text" class="form-control" placeholder="Enter Attribute Value" maxlength="60" name="attribute_values[]" required>
                    </div>
                </div>
                <div class="col-auto">
                    <button type="button" class="mb-3 pt-2 btn btn-icon btn-circle btn-sm btn-soft-danger remove-row">
                        <i class="las la-times"></i>
                    </button>
                </div>
            `;

            // Insert the new row before the Add button
            addBtn.parentNode.insertBefore(newRow, addBtn);
        }

        // Remove row
        const removeBtn = e.target.closest(".remove-row");
        if (removeBtn) {
            const rowToRemove = removeBtn.closest(".attribute-row") || removeBtn.closest(".row");
            if (rowToRemove) {
                const hiddenIdInput = rowToRemove.querySelector('input[name="attribute_value_ids[]"]');

                // Track deleted IDs if editing existing attribute values
                if (hiddenIdInput && hiddenIdInput.value) {
                    let deletedWrapper = document.querySelector("#deleted-attribute-ids");
                    if (!deletedWrapper) {
                        deletedWrapper = document.createElement("div");
                        deletedWrapper.id = "deleted-attribute-ids";
                        document.querySelector("#attribute-wrapper").appendChild(deletedWrapper);
                    }
                    deletedWrapper.insertAdjacentHTML(
                        "beforeend",
                        `<input type="hidden" name="deleted_attribute_value_ids[]" value="${hiddenIdInput.value}">`
                    );
                }

                // Remove the row visually
                rowToRemove.remove();
            }
        }
    });
});
</script>



@endsection