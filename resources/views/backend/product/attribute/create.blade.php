@extends('backend.layouts.app')
@section('content')

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0 h6">{{translate('Attribute Information')}}</h5>
            </div>
            <div class="card-body">
                <form action="{{ route('attributes.store') }}" method="POST" id="aizSubmitForm">
                    @csrf
                    <div class="form-group mb-3">
                        <label for="name">{{ translate('Attribute Name') }}</label>
                        <input type="text" placeholder="{{ translate('Name') }}" id="name" name="name"
                            class="form-control" required>
                    </div>

                    <div class="form-group mb-3">
                        <label>{{ translate('Attribute Value') }}</label>
                        <div id="attribute-wrapper">
                            <div class="row gutters-5 mb-2 attribute-row">
                                <div class="col">
                                    <div class="form-group">
                                        <input type="text" class="form-control" placeholder="{{translate('Enter Attribute Value')}}" name="attribute_values[]" maxlength="60" required>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <button type="button" class="my-1 pt-2 btn btn-icon btn-circle btn-sm btn-soft-danger remove-row">
                                        <i class="las la-times"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="button" class="btn btn-block border border-dashed hov-bg-soft-secondary fs-14 rounded-0 d-flex align-items-center justify-content-center add-row">
                                <i class="las la-plus"></i>
                                <span class="ml-2">Add More</span>
                            </button>
                        </div>
                    </div>
                    <div class="form-group mb-3 text-right">
                        <button type="submit" class="btn btn-primary">{{ translate('Save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection


@section('script')
<script>
document.addEventListener("DOMContentLoaded", function () {
    let rowIndex = 0;

    document.addEventListener("click", function (e) {
        // Add new row
        if (e.target.closest(".add-row")) {
            const wrapper = e.target.closest(".add-row").parentElement;
            const newRow = document.createElement("div");
            newRow.classList.add("row", "gutters-5", "mb-2", "attribute-row");

            newRow.innerHTML = `
                <div class="col">
                    <div class="form-group">
                        <input type="text" class="form-control" placeholder="Enter Attribute Value" name="attribute_values[]" maxlength="60" required>
                    </div>
                </div>
                <div class="col-auto">
                    <button type="button" class="my-1 pt-2 btn btn-icon btn-circle btn-sm btn-soft-danger remove-row">
                        <i class="las la-times"></i>
                    </button>
                </div>
            `;
            wrapper.insertBefore(newRow, e.target.closest(".add-row"));
            rowIndex++;
        }

        // Remove row
        if (e.target.closest(".remove-row")) {
            const rowToRemove = e.target.closest(".attribute-row");
            if (rowToRemove) {
                rowToRemove.remove();
            }
        }
    });
});
</script>

@endsection