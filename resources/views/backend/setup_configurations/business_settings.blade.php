@extends('backend.layouts.app')

@section('content')
    <!-- Business Settings -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0 h6">{{ translate('Business Settings') }}</h5>
        </div>

        <div class="card-body">
            <form action="{{ route('business_info.update') }}" method="POST" enctype="multipart/form-data">
                @csrf
                @if (addon_is_activated('gst_system'))
                {{-- GSTIN Number --}}
                <div class="row mb-3">
                    <div class="col-md-2">
                        <label>
                            {{ translate('GSTIN Number') }}
                            <span class="text-danger">*</span>
                        </label>
                    </div>
                    <div class="col-md-10">
                        <input type="text" class="form-control" name="gstin_number" placeholder="{{ translate('GSTIN Number') }}"  value="{{ $business_info['gstin'] ?? '' }}" required>
                    </div>
                </div>

                {{-- GSTIN Certificate --}}
                <div class="row mb-4">
                    <div class="col-md-2">
                        <label>
                            {{ translate('GSTIN Certificate') }}
                            @if (!isset($business_info['gstin_certificate']) || empty($business_info['gstin_certificate']))
                                <span class="text-danger">*</span>
                            @endif
                        </label>
                    </div>
                    <div class="col-md-10">
                        <div class="custom-file mb-3">
                            <label class="custom-file-label">
                                <input type="file" class="custom-file-input preview-input" data-preview="#gst_preview" name="gstin_certificate" id="gstin_certificate" accept=".jpg,.jpeg,.png,.bmp,application/pdf">
                                <span class="custom-file-name">{{ translate('Choose file') }}</span>
                            </label>
                        </div>
                        <div id="gst_preview" class="mt-2"></div>

                        @if (isset($business_info['gstin_certificate']) && !empty($business_info['gstin_certificate']))
                            <div class="mt-2 text-center">
                                <a onclick="showFileInModal('{{ my_asset($business_info['gstin_certificate']) }}')" 
                                   class="btn btn-sm btn-info text-white">
                                    {{ translate('View Current Certificate') }}
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
                @endif

                {{-- Certificate Number (VAT / TIN / BIN) --}}
                <div class="row mb-3">
                    <div class="col-md-2">
                        <label>{{ translate('VAT / TIN / BIN Number') }}</label>
                    </div>
                    <div class="col-md-10">
                        <input type="text" class="form-control" name="certificate_number" placeholder="{{ translate('VAT / TIN / BIN Number') }}"  value="{{ $business_info['certificate_number'] ?? '' }}">
                    </div>
                </div>

                {{-- Registration Certificate / Trade License --}}
                <div class="row mb-4">
                    <div class="col-md-2">
                        <label>
                            {{ translate('Reg Certificate / Trade License / Sale Tax Permit') }}
                            @if (!isset($business_info['certificate']) || empty($business_info['certificate']))
                                <span class="text-danger">*</span>
                            @endif
                        </label>
                    </div>
                    <div class="col-md-10">
                        <div class="custom-file mb-3">
                            <label class="custom-file-label">
                                <input type="file" class="custom-file-input preview-input" data-preview="#certificate_preview" name="certificate" id="certificate" accept=".jpg,.jpeg,.png,.bmp,application/pdf">
                                <span class="custom-file-name">{{ translate('Choose file') }}</span>
                            </label>
                        </div>
                        <div id="certificate_preview" class="mt-2"></div>

                        @if (isset($business_info['certificate']) && !empty($business_info['certificate']))
                            <div class="mt-2 text-center">
                                <a onclick="showFileInModal('{{ my_asset($business_info['certificate']) }}')" 
                                   class="btn btn-sm btn-info text-white">
                                    {{ translate('View Current Certificate') }}
                                </a>
                            </div>
                        @endif
                    </div>
                </div>

                @if (get_active_countries()->count() > 1)
                <div class="row mb-3">
                    <div class="col-md-2">
                        <label>{{ translate('Country') }} <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-md-10">
                        <select class="form-control aiz-selectpicker" data-live-search="true" 
                                data-placeholder="{{ translate('Select your country') }}" 
                                name="country_id" required>
                            <option value="">{{ translate('Select your country') }}</option>
                            @foreach (\App\Models\Country::where('status', 1)->get() as $country)
                            <option value="{{ $country->id }}" 
                                    @if(isset($business_info['country']) && $business_info['country'] == $country->name) selected @endif>
                                {{ $country->name }}
                            </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @elseif(get_active_countries()->count() == 1)
                <input type="hidden" name="country_id" value="{{ get_active_countries()->first()->id }}">
                @endif

                <div class="row mb-3">
                    <div class="col-md-2">
                        <label>{{ translate('State') }} <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-md-10">
                        <select class="form-control aiz-selectpicker" data-live-search="true" 
                                name="state_id" required></select>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-2">
                        <label for="address">{{ translate('Address') }}</label>
                    </div>
                    <div class="col-md-10">
                        <textarea placeholder="{{ translate('Type Address') }}" name="address"  required class="form-control" rows="3">{{ $business_info['address'] ?? '' }}</textarea>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-2">
                        <label>{{ translate('Inhouse Shop Logo') }} <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-md-10">
                        <div class="input-group " data-toggle="aizuploader" data-type="image">
                            <div class="input-group-prepend">
                                <div class="input-group-text bg-soft-secondary">{{ translate('Browse') }}</div>
                            </div>
                            <div class="form-control file-amount">{{ translate('Choose File') }}</div>
                            <input type="hidden" name="types[]" value="shop_logo">
                            <input type="hidden" name="shop_logo" value="{{ $business_info['shop_logo'] ?? '' }}" class="selected-files">
                        </div>
                        <div class="file-preview box"></div>
                        <small class="text-muted">{{ translate('Minimum dimensions required: 32px width X 32px height.') }}</small>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-2">
                        <label>{{ translate('Invoice Logo') }} <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-md-10">
                        <div class="input-group " data-toggle="aizuploader" data-type="image">
                            <div class="input-group-prepend">
                                <div class="input-group-text bg-soft-secondary">{{ translate('Browse') }}</div>
                            </div>
                            <div class="form-control file-amount">{{ translate('Choose File') }}</div>
                            <input type="hidden" name="types[]" value="invoice_logo">
                            <input type="hidden" name="invoice_logo" value="{{ $business_info['invoice_logo'] ?? '' }}" class="selected-files">
                        </div>
                        <div class="file-preview box"></div>
                        <small class="text-muted">{{ translate('Minimum dimensions required: 32px width X 32px height.') }}</small> 
                    </div>
                </div>

                <div class="form-group mt-4 text-right">
                    <button type="submit" class="btn btn-sm btn-primary">
                        {{ translate('Save') }}
                    </button>
                </div>

            </form>
        </div>
    </div>
@endsection

@section('modal')
<div class="modal fade" id="filePreviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">{{ translate('File Preview') }}</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body" style="min-height: 500px;">
        <div id="filePreviewContainer" class="text-center"></div>
      </div>
    </div>
  </div>
</div>
@endsection

@section('script')
<script>
    $(document).on('change', '[name=country_id]', function() {
        var country_id = $(this).val();
        get_states(country_id);
    });

    $(document).ready(function() {
        var country_id = $('[name=country_id]').val();
        if (country_id) {
            get_states(country_id);
        }
    });

    function get_states(country_id) {
        var savedStateName = "{{ $business_info['state'] ?? '' }}";
        $.ajax({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            url: "{{ route('admin.get-state') }}",
            type: 'POST',
            data: { country_id: country_id },
            success: function(response) {
                var obj = JSON.parse(response);
                if (obj && obj.length > 0) {
                    var select = $('[name="state_id"]');
                    select.html(obj);
                    
                    if (savedStateName) {
                        select.find('option').each(function() {
                            if ($(this).text().trim() === savedStateName.trim()) {
                                $(this).prop('selected', true);
                            }
                        });
                    }
                    
                    AIZ.plugins.bootstrapSelect('refresh');
                }
            }
        });
    }

    function showFileInModal(fileUrl) {
        const ext = fileUrl.split('.').pop().toLowerCase();
        const container = document.getElementById('filePreviewContainer');
        container.innerHTML = '';

        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
            const img = document.createElement('img');
            img.src = fileUrl;
            img.style.maxWidth = '100%';
            img.style.maxHeight = '600px';
            container.appendChild(img);
        } else if (ext === 'pdf') {
            const iframe = document.createElement('iframe');
            iframe.src = fileUrl;
            iframe.style.width = '100%';
            iframe.style.height = '600px';
            iframe.frameBorder = 0;
            container.appendChild(iframe);
        } else {
            container.innerHTML = '<p class="text-danger">Unsupported file format.</p>';
        }

        $('#filePreviewModal').modal('show');
    }

    $(document).on('change', '.preview-input', function () {
        let input = this;
        let previewBox = $($(this).data('preview'));
        let fileName = input.files[0]?.name || '';

        $(this).siblings('.custom-file-name').text(fileName || '{{ translate('Choose file') }}');

        previewBox.html('');

        if (input.files && input.files[0]) {
            let file = input.files[0];
            let fileType = file.type;

            if (fileType.startsWith('image/')) {
                let reader = new FileReader();
                reader.onload = function (e) {
                    previewBox.html(
                        '<img src="' + e.target.result + '" class="preview-img img-fluid" style="max-height:120px;">'
                    );
                };
                reader.readAsDataURL(file);
            }
            else if (fileType === 'application/pdf') {
                previewBox.html(`
                    <div class="pdf-preview border rounded text-center position-relative" 
                         style="width:120px; height:120px; background:#f8f9fa;">
                        <i class="las la-file-pdf" style="font-size:60px; color:#e74c3c; opacity:0.7; position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);"></i>
                        <small class="text-muted position-absolute bottom-0 start-0 end-0 px-1 text-truncate">
                            ${fileName}
                        </small>
                    </div>
                `);
            }
        }
    });
</script>

@if(get_active_countries()->count() == 1)
<script>
    $(document).ready(function() {
        get_states({{ get_active_countries()->first()->id }});
    });
</script>
@endif
@endsection